"""
User profile: PUT /users/me
"""
from fastapi import APIRouter, Request
from app.database import get_db
from app.utils    import ok, err, require_roles, to_oid, serial

router = APIRouter(prefix="/users", tags=["users"])


@router.put("/me")
async def update_me(request: Request):
    payload  = require_roles(request, ["any"])
    body     = await request.json()
    allowed  = {"fullName", "avatarUrl", "stream"}
    unknown  = set(body) - allowed
    if unknown:
        return err(f"Unexpected fields: {', '.join(unknown)}", 422)

    set_doc = {}
    if "fullName" in body:
        name = body["fullName"].strip()
        if not name:
            return err("fullName cannot be empty", 422)
        set_doc["fullName"] = name
    if "avatarUrl" in body:
        set_doc["avatarUrl"] = str(body["avatarUrl"])
    if "stream" in body:
        stream = body["stream"]
        if stream is not None and stream not in ("natural", "social"):
            return err("stream must be natural, social, or null", 422)
        set_doc["stream"] = stream

    if not set_doc:
        return err("Nothing to update", 422)

    db  = get_db()
    oid = to_oid(payload["sub"])
    db.users.update_one({"_id": oid}, {"$set": set_doc})
    user = db.users.find_one({"_id": oid})
    safe = serial(user)
    safe.pop("passwordHash", None)
    return ok(safe, "Profile updated")
