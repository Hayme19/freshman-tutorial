import re
from fastapi import APIRouter, Request
from app.database import get_db
from app.utils    import ok, err, require_roles, to_oid, serial

router = APIRouter(prefix="/admin", tags=["admin"])
VALID_ROLES = ("student", "tutor", "instructor", "admin")


@router.get("/users")
async def list_users(request: Request, page: int = 1, limit: int = 20,
                     role: str = "", q: str = ""):
    require_roles(request, ["admin"])
    db    = get_db()
    limit = min(100, max(1, limit))
    skip  = (max(1, page) - 1) * limit
    f: dict = {}
    if role in VALID_ROLES:
        f["role"] = role
    if q.strip():
        pattern = re.compile(re.escape(q.strip()), re.IGNORECASE)
        f["$or"] = [{"fullName": pattern}, {"email": pattern}]

    total = db.users.count_documents(f)
    users = list(db.users.find(f, {"passwordHash": 0}).sort("createdAt", -1).skip(skip).limit(limit))
    return ok({"users": users, "total": total, "page": page, "limit": limit})


@router.put("/users/{user_id}/role")
async def update_role(user_id: str, request: Request):
    require_roles(request, ["admin"])
    body     = await request.json()
    new_role = body.get("role", "")
    if new_role not in VALID_ROLES:
        return err(f"role must be one of: {', '.join(VALID_ROLES)}", 422)

    db  = get_db()
    oid = to_oid(user_id)

    if new_role != "admin":
        target = db.users.find_one({"_id": oid})
        if not target:
            return err("User not found", 404)
        if target.get("role") == "admin":
            if db.users.count_documents({"role": "admin"}) <= 1:
                return err("Cannot remove the last admin", 409)

    result = db.users.update_one({"_id": oid}, {"$set": {"role": new_role}})
    if result.matched_count == 0:
        return err("User not found", 404)
    return ok({"role": new_role}, "Role updated")
