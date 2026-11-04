"""
A+ Freshman Tutorial Platform — Python FastAPI backend
Run:  uvicorn main:app --reload --port 8000
"""
import os
from fastapi import FastAPI, Request
from fastapi.middleware.cors import CORSMiddleware
from fastapi.responses import JSONResponse
from dotenv import load_dotenv

load_dotenv()

from app.routers import (
    auth, users, courses, lessons, enrollments,
    progress, quizzes, submissions, forum,
    announcements, admin, reports, certificates,
    fitness, notifications,
)

app = FastAPI(title="A+ Freshman Tutorial API", version="1.0.0")

# ── CORS ──────────────────────────────────────────────────────────────────────
raw_origins = os.getenv("FRONTEND_URL", "http://localhost:5500")
origins = [o.strip() for o in raw_origins.split(",") if o.strip()]

app.add_middleware(
    CORSMiddleware,
    allow_origins=origins,
    allow_credentials=True,
    allow_methods=["*"],
    allow_headers=["*"],
)

# ── Routers ───────────────────────────────────────────────────────────────────
PREFIX = "/api"

app.include_router(auth.router,           prefix=PREFIX)
app.include_router(users.router,          prefix=PREFIX)
app.include_router(courses.router,        prefix=PREFIX)
app.include_router(lessons.router,        prefix=PREFIX)
app.include_router(enrollments.router,    prefix=PREFIX)
app.include_router(progress.router,       prefix=PREFIX)
app.include_router(quizzes.router,        prefix=PREFIX)
app.include_router(submissions.router,    prefix=PREFIX)
app.include_router(forum.router,          prefix=PREFIX)
app.include_router(announcements.router,  prefix=PREFIX)
app.include_router(admin.router,          prefix=PREFIX)
app.include_router(reports.router,        prefix=PREFIX)
app.include_router(certificates.router,   prefix=PREFIX)
app.include_router(fitness.router,        prefix=PREFIX)
app.include_router(notifications.router,  prefix=PREFIX)

# ── Health check ──────────────────────────────────────────────────────────────
@app.get("/api/health")
def health():
    return {"success": True, "message": "API is running"}

# ── Global error handler ──────────────────────────────────────────────────────
from fastapi.exceptions import RequestValidationError
from fastapi import HTTPException as FastAPIHTTPException

@app.exception_handler(FastAPIHTTPException)
async def http_exception_handler(request: Request, exc: FastAPIHTTPException):
    return JSONResponse({"success": False, "data": None, "message": str(exc.detail)},
                        status_code=exc.status_code)

@app.exception_handler(RequestValidationError)
async def validation_exception_handler(request: Request, exc: RequestValidationError):
    return JSONResponse({"success": False, "data": None, "message": "Validation error"},
                        status_code=422)
