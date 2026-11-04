"""
Quizzes: get, attempt, create, update
"""
from datetime import datetime, timezone
from fastapi  import APIRouter, Request
from app.database import get_db
from app.utils    import ok, err, require_roles, to_oid, serial, get_payload

router = APIRouter(prefix="/quizzes", tags=["quizzes"])


@router.get("/{quiz_id}")
async def get_quiz(quiz_id: str, request: Request):
    payload = require_roles(request, ["any"])
    db      = get_db()
    quiz    = db.quizzes.find_one({"_id": to_oid(quiz_id)})
    if not quiz:
        return err("Quiz not found", 404)

    data = serial(quiz)
    # Hide answers from students
    if payload.get("role") == "student":
        for q in data.get("questions", []):
            q.pop("correctIndex", None)
    return ok(data)


@router.post("/{quiz_id}/attempt")
async def attempt_quiz(quiz_id: str, request: Request):
    payload     = require_roles(request, ["student"])
    student_oid = to_oid(payload["sub"])
    quiz_oid    = to_oid(quiz_id)

    db   = get_db()
    quiz = db.quizzes.find_one({"_id": quiz_oid})
    if not quiz:
        return err("Quiz not found", 404)

    body      = await request.json()
    answers   = body.get("answers", [])
    questions = quiz.get("questions", [])
    total     = len(questions)
    score     = 0
    results   = []

    for i, q in enumerate(questions):
        given   = int(answers[i]) if i < len(answers) else -1
        correct = int(q.get("correctIndex", 0))
        is_right = given == correct
        if is_right:
            score += 1
        results.append({"questionIndex": i, "given": given,
                         "correct": correct, "isCorrect": is_right})

    attempt_doc = {
        "quizId":    quiz_oid,
        "studentId": student_oid,
        "score":     score,
        "total":     total,
        "answers":   results,
        "takenAt":   datetime.now(timezone.utc),
    }
    result = db.quizAttempts.insert_one(attempt_doc)
    attempt_doc["_id"] = result.inserted_id
    return ok(serial(attempt_doc), "Quiz submitted")


@router.post("")
async def create_quiz(request: Request):
    payload = require_roles(request, ["instructor", "admin"])
    body    = await request.json()

    course_oid = to_oid(body.get("courseId", ""))
    title      = body.get("title", "").strip()
    questions  = body.get("questions", [])

    if not title:
        return err("title is required", 422)
    if not questions:
        return err("questions array is required", 422)

    for q in questions:
        if not q.get("text") or "options" not in q or "correctIndex" not in q:
            return err("Each question needs text, options, and correctIndex", 422)

    db  = get_db()
    doc = {
        "courseId":  course_oid,
        "lessonId":  to_oid(body["lessonId"]) if body.get("lessonId") else None,
        "title":     title,
        "questions": questions,
    }
    result  = db.quizzes.insert_one(doc)
    doc["_id"] = result.inserted_id
    return ok(doc, "Quiz created", 201)


@router.put("/{quiz_id}")
async def update_quiz(quiz_id: str, request: Request):
    require_roles(request, ["instructor", "admin"])
    oid  = to_oid(quiz_id)
    db   = get_db()
    quiz = db.quizzes.find_one({"_id": oid})
    if not quiz:
        return err("Quiz not found", 404)

    body    = await request.json()
    set_doc = {}
    if "title"     in body: set_doc["title"]     = body["title"]
    if "questions" in body: set_doc["questions"] = body["questions"]
    if not set_doc:
        return err("Nothing to update", 422)

    db.quizzes.update_one({"_id": oid}, {"$set": set_doc})
    return ok(db.quizzes.find_one({"_id": oid}), "Quiz updated")
