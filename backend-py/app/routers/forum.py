from datetime import datetime, timezone
from fastapi  import APIRouter, Request
from app.database import get_db
from app.utils    import ok, err, require_roles, to_oid, serial

router = APIRouter(prefix="/forum", tags=["forum"])


@router.get("")
async def list_posts(request: Request, courseId: str = ""):
    require_roles(request, ["any"])
    db = get_db()
    f  = {}
    if courseId:
        f["courseId"] = to_oid(courseId)
    posts = list(db.forumPosts.find(f).sort("createdAt", -1))
    return ok(posts)


@router.post("")
async def create_post(request: Request):
    payload = require_roles(request, ["any"])
    body    = await request.json()

    course_oid = to_oid(body.get("courseId", ""))
    author_oid = to_oid(payload["sub"])
    title      = body.get("title", "").strip()
    body_text  = body.get("body",  "").strip()

    if not title or not body_text:
        return err("title and body are required", 422)

    db  = get_db()
    doc = {
        "courseId":  course_oid,
        "authorId":  author_oid,
        "title":     title,
        "body":      body_text,
        "replies":   [],
        "createdAt": datetime.now(timezone.utc),
    }
    result  = db.forumPosts.insert_one(doc)
    doc["_id"] = result.inserted_id
    return ok(serial(doc), "Post created", 201)


@router.post("/{post_id}/reply")
async def reply(post_id: str, request: Request):
    payload   = require_roles(request, ["any"])
    post_oid  = to_oid(post_id)
    author_oid = to_oid(payload["sub"])

    db   = get_db()
    post = db.forumPosts.find_one({"_id": post_oid})
    if not post:
        return err("Post not found", 404)

    body      = await request.json()
    body_text = body.get("body", "").strip()
    if not body_text:
        return err("body is required", 422)

    reply_doc = {
        "authorId":  author_oid,
        "body":      body_text,
        "createdAt": datetime.now(timezone.utc),
    }
    db.forumPosts.update_one({"_id": post_oid}, {"$push": {"replies": reply_doc}})
    return ok(serial(reply_doc), "Reply added", 201)
