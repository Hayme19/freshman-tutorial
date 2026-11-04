from fastapi import APIRouter, Request
from app.database import get_db
from app.utils    import ok, err, require_roles, to_oid, serial

router = APIRouter(prefix="/certificates", tags=["certificates"])


@router.get("")
async def list_mine(request: Request):
    payload     = require_roles(request, ["any"])
    student_oid = to_oid(payload["sub"])
    db          = get_db()
    certs       = list(db.certificates.find({"studentId": student_oid}).sort("issuedAt", -1))
    return ok(certs)


@router.get("/{cert_id}")
async def get_cert(cert_id: str, request: Request):
    payload = require_roles(request, ["any"])
    oid     = to_oid(cert_id)
    db      = get_db()
    cert    = db.certificates.find_one({"_id": oid})
    if not cert:
        return err("Certificate not found", 404)
    if payload.get("role") != "admin" and str(cert["studentId"]) != payload["sub"]:
        return err("Forbidden", 403)
    return ok(cert)
