"""
Course management: list, get, create, update, delete
"""
import re
from datetime import datetime, timezone
from fastapi  import APIRouter, Request
from bson     import ObjectId

from app.database import get_db
from app.utils    import ok, err, require_roles, to_oid, serial, get_payload

router = APIRouter(prefix="/courses", tags=["courses"])

VALID_STREAMS = ("natural", "social")
VALID_GROUPS  = ("common", "natural", "social", "digital")
VALID_LEVELS  = ("beginner", "intermediate")


@router.get("")
async def list_courses(request: Request):
    db     = get_db()
    params = request.query_params
    filter_doc: dict = {}

    # Auth optional
    try:
        payload = get_payload(request)
        role    = payload.get("role", "guest")
    except Exception:
        role = "guest"

    if role not in ("instructor", "admin"):
        filter_doc["isPublished"] = True

    stream = params.get("stream")
    if stream in VALID_STREAMS:
        filter_doc["streams"] = stream

    group = params.get("group")
    if group in VALID_GROUPS:
        filter_doc["group"] = group

    q = params.get("q", "").strip()
    if q:
        pattern = re.compile(re.escape(q), re.IGNORECASE)
        filter_doc["$or"] = [{"title": pattern}, {"description": pattern}]

    courses = list(db.courses.find(filter_doc).sort("title", 1))
    return ok(courses)


@router.get("/{course_id}")
async def get_course(course_id: str, request: Request):
    db  = get_db()
    oid = to_oid(course_id)
    try:
        payload = get_payload(request)
        role    = payload.get("role", "guest")
    except Exception:
        role = "guest"

    f = {"_id": oid}
    if role not in ("instructor", "admin"):
        f["isPublished"] = True

    course = db.courses.find_one(f)
    if not course:
        return err("Course not found", 404)
    return ok(course)


@router.post("")
async def create_course(request: Request):
    payload = require_roles(request, ["instructor", "admin"])
    body    = await request.json()

    allowed = {"code", "title", "description", "streams", "group", "level", "isPublished"}
    unknown = set(body) - allowed
    if unknown:
        return err(f"Unexpected fields: {', '.join(unknown)}", 422)

    title = body.get("title", "").strip()
    code  = body.get("code", "").strip().upper()
    if not title or not code:
        return err("title and code are required", 422)

    streams = body.get("streams", [])
    if isinstance(streams, str):
        streams = [streams]
    for s in streams:
        if s not in VALID_STREAMS:
            return err(f"Invalid stream: {s}", 422)

    group = body.get("group", "common")
    if group not in VALID_GROUPS:
        return err("Invalid group", 422)

    level = body.get("level", "beginner")
    if level not in VALID_LEVELS:
        return err("Invalid level", 422)

    db  = get_db()
    doc = {
        "code":         code,
        "title":        title,
        "description":  body.get("description", ""),
        "streams":      streams,
        "group":        group,
        "level":        level,
        "instructorId": ObjectId(payload["sub"]),
        "isPublished":  bool(body.get("isPublished", False)),
        "createdAt":    datetime.now(timezone.utc),
    }
    result = db.courses.insert_one(doc)
    doc["_id"] = result.inserted_id
    return ok(doc, "Course created", 201)


@router.put("/{course_id}")
async def update_course(course_id: str, request: Request):
    payload = require_roles(request, ["instructor", "admin"])
    oid     = to_oid(course_id)
    db      = get_db()
    course  = db.courses.find_one({"_id": oid})
    if not course:
        return err("Course not found", 404)

    if payload["role"] != "admin" and str(course.get("instructorId")) != payload["sub"]:
        return err("Forbidden", 403)

    body    = await request.json()
    allowed = {"title", "description", "streams", "group", "level", "isPublished"}
    set_doc = {}
    for field in allowed:
        if field not in body:
            continue
        if field == "streams":
            streams = body["streams"] if isinstance(body["streams"], list) else [body["streams"]]
            for s in streams:
                if s not in VALID_STREAMS:
                    return err(f"Invalid stream: {s}", 422)
            set_doc["streams"] = streams
        elif field == "group":
            if body["group"] not in VALID_GROUPS:
                return err("Invalid group", 422)
            set_doc["group"] = body["group"]
        elif field == "level":
            if body["level"] not in VALID_LEVELS:
                return err("Invalid level", 422)
            set_doc["level"] = body["level"]
        elif field == "isPublished":
            set_doc["isPublished"] = bool(body["isPublished"])
        else:
            set_doc[field] = str(body[field])

    if not set_doc:
        return err("Nothing to update", 422)

    db.courses.update_one({"_id": oid}, {"$set": set_doc})
    return ok(db.courses.find_one({"_id": oid}), "Course updated")


@router.delete("/{course_id}")
async def delete_course(course_id: str, request: Request):
    payload = require_roles(request, ["instructor", "admin"])
    oid     = to_oid(course_id)
    db      = get_db()
    course  = db.courses.find_one({"_id": oid})
    if not course:
        return err("Course not found", 404)
    if payload["role"] != "admin" and str(course.get("instructorId")) != payload["sub"]:
        return err("Forbidden", 403)
    db.courses.delete_one({"_id": oid})
    return ok(None, "Course deleted")
