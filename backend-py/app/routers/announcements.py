from datetime import datetime, timezone
from fastapi  import APIRouter, Request
from app.database import get_db
from app.utils    import ok, err, require_roles, to_oid, serial

router = APIRouter(prefix="/announcements", tags=["announcements"])


@router.get("")
async def list_announcements(request: Request):
    require_roles(request, ["any"])
    db = get_db()
    return ok(list(db.announcements.find().sort("createdAt", -1)))


@router.post("")
async def create_announcement(request: Request):
    payload = require_roles(request, ["instructor", "admin"])
    body    = await request.json()
    title   = body.get("title",   "").strip()
    message = body.get("message", "").strip()
    if not title or not message:
        return err("title and message are required", 422)

    db  = get_db()
    doc = {
        "authorId":  to_oid(payload["sub"]),
        "title":     title,
        "message":   message,
        "createdAt": datetime.now(timezone.utc),
    }
    result  = db.announcements.insert_one(doc)
    doc["_id"] = result.inserted_id
    return ok(serial(doc), "Announcement created", 201)
