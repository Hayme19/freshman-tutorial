from fastapi import APIRouter, Request
from app.database import get_db
from app.utils    import ok, err, require_roles, to_oid

router = APIRouter(prefix="/notifications", tags=["notifications"])


@router.get("")
async def list_notifications(request: Request):
    payload = require_roles(request, ["any"])
    db      = get_db()
    notes   = list(db.notifications.find({"userId": to_oid(payload["sub"])}).sort("createdAt", -1))
    return ok(notes)


@router.put("/{note_id}/read")
async def mark_read(note_id: str, request: Request):
    payload = require_roles(request, ["any"])
    oid     = to_oid(note_id)
    db      = get_db()
    note    = db.notifications.find_one({"_id": oid})
    if not note:
        return err("Notification not found", 404)
    if str(note["userId"]) != payload["sub"]:
        return err("Forbidden", 403)
    db.notifications.update_one({"_id": oid}, {"$set": {"isRead": True}})
    return ok(None, "Marked as read")
