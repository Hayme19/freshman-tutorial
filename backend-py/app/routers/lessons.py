"""
Lesson management
GET  /courses/{id}/lessons  — enrolled or free
POST /lessons               — instructor, admin
PUT  /lessons/{id}          — instructor, admin
DELETE /lessons/{id}        — instructor, admin
"""
from fastapi import APIRouter, Request
from app.database import get_db
from app.utils    import ok, err, require_roles, to_oid, get_payload

router = APIRouter(tags=["lessons"])


@router.get("/courses/{course_id}/lessons")
async def list_lessons(course_id: str, request: Request):
    db        = get_db()
    course_oid = to_oid(course_id)

    if not db.courses.find_one({"_id": course_oid}):
        return err("Course not found", 404)

    show_all = False
    try:
        payload = get_payload(request)
        role    = payload.get("role", "student")
        if role in ("instructor", "admin", "tutor"):
            show_all = True
        else:
            enrolled = db.enrollments.count_documents({
                "studentId": to_oid(payload["sub"]),
                "courseId":  course_oid,
            })
            show_all = enrolled > 0
    except Exception:
        show_all = False

    f = {"courseId": course_oid}
    if not show_all:
        f["isFree"] = True

    lessons = list(db.lessons.find(f).sort([("unit", 1), ("order", 1)]))
    return ok(lessons)


@router.post("/lessons")
async def create_lesson(request: Request):
    payload = require_roles(request, ["instructor", "admin"])
    body    = await request.json()

    allowed = {"courseId", "unit", "title", "content", "codeExample", "videoUrl", "order", "isFree"}
    unknown = set(body) - allowed
    if unknown:
        return err(f"Unexpected fields: {', '.join(unknown)}", 422)

    course_oid = to_oid(body.get("courseId", ""))
    title      = body.get("title", "").strip()
    if not title:
        return err("title is required", 422)

    db  = get_db()
    doc = {
        "courseId":    course_oid,
        "unit":        int(body.get("unit", 1)),
        "title":       title,
        "content":     body.get("content", ""),
        "codeExample": body.get("codeExample", ""),
        "videoUrl":    body.get("videoUrl", ""),
        "order":       int(body.get("order", 1)),
        "isFree":      bool(body.get("isFree", False)),
    }
    result = db.lessons.insert_one(doc)
    doc["_id"] = result.inserted_id
    return ok(doc, "Lesson created", 201)


@router.put("/lessons/{lesson_id}")
async def update_lesson(lesson_id: str, request: Request):
    require_roles(request, ["instructor", "admin"])
    oid    = to_oid(lesson_id)
    db     = get_db()
    lesson = db.lessons.find_one({"_id": oid})
    if not lesson:
        return err("Lesson not found", 404)

    body    = await request.json()
    allowed = {"unit", "title", "content", "codeExample", "videoUrl", "order", "isFree"}
    set_doc = {}
    for f in allowed:
        if f not in body:
            continue
        if f in ("unit", "order"):
            set_doc[f] = int(body[f])
        elif f == "isFree":
            set_doc[f] = bool(body[f])
        else:
            set_doc[f] = str(body[f])

    if not set_doc:
        return err("Nothing to update", 422)

    db.lessons.update_one({"_id": oid}, {"$set": set_doc})
    return ok(db.lessons.find_one({"_id": oid}), "Lesson updated")


@router.delete("/lessons/{lesson_id}")
async def delete_lesson(lesson_id: str, request: Request):
    require_roles(request, ["instructor", "admin"])
    oid    = to_oid(lesson_id)
    db     = get_db()
    lesson = db.lessons.find_one({"_id": oid})
    if not lesson:
        return err("Lesson not found", 404)
    db.lessons.delete_one({"_id": oid})
    return ok(None, "Lesson deleted")
