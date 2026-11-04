"""
Seed script — run once:  python seed.py
Creates indexes and inserts the 16 courses + sample content for 4 priority courses.
Safe to run multiple times (upserts by code).
"""
import os, json
from datetime import datetime, timezone
from pathlib  import Path
from pymongo  import MongoClient, ASCENDING
from pymongo.errors import DuplicateKeyError
from dotenv   import load_dotenv

load_dotenv()

URI    = os.getenv("MONGODB_URI", "")
DBNAME = os.getenv("DB_NAME", "tutorialdb")

if not URI:
    print("ERROR: MONGODB_URI is not set. Copy .env.example to .env and fill it in.")
    exit(1)

client = MongoClient(URI)
db     = client[DBNAME]
print(f"Connected to {DBNAME}")

# ── 1. Indexes ────────────────────────────────────────────────────────────────
print("\nCreating indexes...")
db.users.create_index("email", unique=True, name="users_email_unique")
db.courses.create_index("streams", name="courses_streams")
db.lessons.create_index([("courseId", ASCENDING), ("order", ASCENDING)], name="lessons_courseId_order")
db.enrollments.create_index([("studentId", ASCENDING), ("courseId", ASCENDING)],
                             unique=True, name="enrollments_unique")
db.quizAttempts.create_index("studentId", name="quizAttempts_studentId")
db.loginAttempts.create_index("createdAt", expireAfterSeconds=3600, name="loginAttempts_ttl")
print("Indexes created.")

# ── 2. Courses ─────────────────────────────────────────────────────────────────
COURSES = [
  {"code":"ENGL101","title":"Communicative English I",     "streams":["natural","social"],"group":"common"},
  {"code":"ANTH101","title":"Social Anthropology",          "streams":["natural","social"],"group":"common"},
  {"code":"EMTE101","title":"Emerging Technologies",        "streams":["natural","social"],"group":"common"},
  {"code":"GLTR101","title":"Global Trends",                "streams":["natural","social"],"group":"common"},
  {"code":"PSYC101","title":"General Psychology",           "streams":["natural","social"],"group":"common"},
  {"code":"LOCT101","title":"Logic and Critical Thinking",  "streams":["natural","social"],"group":"common"},
  {"code":"SPRT101","title":"Sport (Physical Fitness)",     "streams":["natural","social"],"group":"common"},
  {"code":"PHYS101","title":"General Physics",              "streams":["natural"],         "group":"natural"},
  {"code":"MATH101","title":"Mathematics for Natural Sciences","streams":["natural"],      "group":"natural"},
  {"code":"GEOG101","title":"Geography",                    "streams":["natural"],         "group":"natural"},
  {"code":"MCEN101","title":"Moral and Citizenship Education","streams":["natural"],       "group":"natural"},
  {"code":"ECON101","title":"Economics",                    "streams":["social"],          "group":"social"},
  {"code":"ENTR101","title":"Entrepreneurship",             "streams":["social"],          "group":"social"},
  {"code":"HIST101","title":"History",                      "streams":["social"],          "group":"social"},
  {"code":"INCL101","title":"Inclusiveness",                "streams":["social"],          "group":"social"},
  {"code":"WEBD101","title":"Web Development",              "streams":["natural","social"],"group":"digital"},
]

print("\nUpserting courses...")
course_map = {}
for c in COURSES:
    existing = db.courses.find_one({"code": c["code"]})
    if existing:
        course_map[c["code"]] = existing["_id"]
        continue
    result = db.courses.insert_one({
        "code": c["code"], "title": c["title"], "description": "",
        "streams": c["streams"], "group": c["group"],
        "level": "beginner", "instructorId": None,
        "isPublished": True, "createdAt": datetime.now(timezone.utc),
    })
    course_map[c["code"]] = result.inserted_id
print(f"  {len(course_map)} courses ready.")

# ── 3. Sample content helper ──────────────────────────────────────────────────
def seed_course(code, lessons, quiz_questions, exercise_instruction, starter_code=""):
    cid = course_map.get(code)
    if not cid:
        print(f"  [{code}] not found, skipping."); return
    if db.lessons.count_documents({"courseId": cid}) > 0:
        print(f"  [{code}] already seeded — skipping."); return

    lesson_ids = []
    for i, l in enumerate(lessons):
        r = db.lessons.insert_one({
            "courseId": cid, "unit": 1, "title": l["title"],
            "content": l["content"], "codeExample": l.get("code",""),
            "videoUrl": "", "order": i+1, "isFree": (i == 0),
        })
        lesson_ids.append(r.inserted_id)

    db.exercises.insert_one({
        "courseId": cid, "lessonId": lesson_ids[0],
        "instruction": exercise_instruction, "starterCode": starter_code,
    })
    db.quizzes.insert_one({
        "courseId": cid, "lessonId": lesson_ids[-1],
        "title": "End-of-Unit Quiz", "questions": quiz_questions,
    })
    print(f"  [{code}] {len(lessons)} lessons, 1 exercise, 1 quiz inserted.")

# ── 4. Priority courses ───────────────────────────────────────────────────────
print("\nSeeding priority courses...")

seed_course("LOCT101",
  lessons=[
    {"title":"Arguments and Propositions",
     "content":"An argument is a set of statements where one (the conclusion) follows from others (the premises).\n\nA proposition is a statement that is either true or false.\n\nTypes:\n- Deductive: conclusion necessarily follows. Key criterion: validity.\n- Inductive: conclusion probably follows. Key criterion: strength.\n\nValidity vs Soundness:\n- Valid = conclusion must be true if premises are true.\n- Sound = valid AND all premises are actually true.\n\nExample:\nP1: All humans are mortal.\nP2: Socrates is a human.\nC: Therefore, Socrates is mortal. (Valid and Sound)"},
    {"title":"Common Logical Fallacies",
     "content":"A fallacy is an error in reasoning.\n\nAd Hominem: Attacking the person instead of the argument.\nStraw Man: Misrepresenting someone's argument to attack it more easily.\nFalse Dilemma: Presenting only two options when more exist.\nSlippery Slope: Claiming one event inevitably leads to extreme consequences.\nAppeal to Authority: Using an authority figure as sole evidence.\nCircular Reasoning: Using the conclusion as a premise.\n\nHow to spot a fallacy:\n1. Identify the claim being made.\n2. Examine the evidence.\n3. Ask whether the evidence actually supports the claim."},
    {"title":"Deductive vs Inductive Reasoning",
     "content":"Deductive: General → Specific. Conclusion is GUARANTEED if premises are true.\nExample: All metals conduct electricity. Copper is a metal. Therefore copper conducts electricity.\n\nInductive: Specific → General. Conclusion is PROBABLE, not guaranteed.\nExample: Every crow I have seen is black. Therefore all crows are black. (Not certain!)\n\nKey differences:\n| | Deductive | Inductive |\n| Direction | General→Specific | Specific→General |\n| Certainty | Guaranteed | Probable |\n| Used in | Mathematics, Logic | Science, Everyday |\n\nAbductive reasoning: Selects the simplest and most likely explanation. Used in medicine and detective work."},
  ],
  quiz_questions=[
    {"text":"A deductive argument guarantees its conclusion when premises are true and the argument is valid.","options":["True","False"],"correctIndex":0},
    {"text":"Which fallacy misrepresents an opponent's position?","options":["Ad Hominem","Straw Man","Slippery Slope","False Dilemma"],"correctIndex":1},
    {"text":"Inductive reasoning moves from general to specific.","options":["True","False"],"correctIndex":1},
    {"text":"An argument is sound if it is valid AND all premises are true.","options":["True","False"],"correctIndex":0},
    {"text":"Which is an example of a false dilemma?","options":["'You must be a genius.'","'Either you support us or you hate us.'","'She said it so it must be wrong.'","'One step leads to chaos.'"],"correctIndex":1},
  ],
  exercise_instruction="Identify whether each argument is deductive or inductive, and check for fallacies:\n1. All prime numbers >2 are odd. 17 is prime. Therefore 17 is odd.\n2. Every student I tutored who studied daily passed. So all students who study daily pass.\n3. You should not listen to her — she is overweight.",
)

seed_course("MATH101",
  lessons=[
    {"title":"Functions",
     "content":"A function f: A → B assigns to each x in A exactly one f(x) in B.\n\nDomain: set of valid inputs.\nCodomain: set the function maps into.\nRange: set of actual outputs {f(x) | x in A}.\n\nCommon types:\n- Linear: f(x) = mx + b\n- Quadratic: f(x) = ax² + bx + c\n- Exponential: f(x) = aˣ\n- Logarithmic: f(x) = log_a(x)\n\nEven functions: f(-x) = f(x) — symmetric about y-axis. Example: x²\nOdd functions: f(-x) = -f(x) — symmetric about origin. Example: x³\n\nComposition: (f∘g)(x) = f(g(x))\nInverse: f⁻¹ satisfies f(f⁻¹(x)) = x"},
    {"title":"Limits and Continuity",
     "content":"The limit lim(x→a) f(x) = L means: as x approaches a, f(x) approaches L.\n\nKey limit: lim(x→0) sin(x)/x = 1\nThis is proved using the squeeze theorem.\n\nOne-sided limits:\n- Left: lim(x→a⁻) f(x)\n- Right: lim(x→a⁺) f(x)\n- Two-sided limit exists iff both one-sided limits are equal.\n\nContinuity at point a:\n1. f(a) is defined.\n2. lim(x→a) f(x) exists.\n3. lim(x→a) f(x) = f(a)\n\nPolynomials are continuous everywhere on their domain."},
    {"title":"Derivatives",
     "content":"The derivative: f'(x) = lim(h→0) [f(x+h) - f(x)] / h\n\nMeasures the instantaneous rate of change (slope of tangent line).\n\nBasic rules:\n- Power rule: d/dx[xⁿ] = nxⁿ⁻¹\n- Constant multiple: d/dx[cf(x)] = cf'(x)\n- Sum: d/dx[f±g] = f'±g'\n- Product: d/dx[fg] = f'g + fg'\n- Quotient: d/dx[f/g] = (f'g - fg') / g²\n- Chain rule: d/dx[f(g(x))] = f'(g(x))·g'(x)\n\nCommon derivatives:\n- d/dx[sin x] = cos x\n- d/dx[cos x] = -sin x\n- d/dx[eˣ] = eˣ\n- d/dx[ln x] = 1/x\n\nApplications: finding max/min (set f'(x)=0), velocity from position."},
  ],
  quiz_questions=[
    {"text":"What is lim(x→0) sin(x)/x?","options":["0","1","undefined","∞"],"correctIndex":1},
    {"text":"Using the power rule, what is d/dx[x⁵]?","options":["5x⁴","5x⁵","x⁴","4x⁵"],"correctIndex":0},
    {"text":"A function is continuous at a point if the limit exists there, even if the function is undefined.","options":["True","False"],"correctIndex":1},
    {"text":"What is d/dx[sin x]?","options":["-cos x","cos x","tan x","-sin x"],"correctIndex":1},
    {"text":"Which rule differentiates f(g(x))?","options":["Product","Quotient","Chain","Power"],"correctIndex":2},
  ],
  exercise_instruction="Given f(x) = 3x³ - 5x² + 2x - 7, find f'(x) using basic differentiation rules. Then set f'(x) = 0 and write the resulting equation.",
  starter_code="# Steps:\n# 1. Apply power rule term by term\n# f'(x) = ?\n# 2. Set f'(x) = 0",
)

seed_course("ECON101",
  lessons=[
    {"title":"Demand and Supply",
     "content":"Demand: quantity consumers are willing and able to buy at various prices.\nLaw of Demand: As price rises, quantity demanded falls (ceteris paribus). Demand curve slopes downward.\n\nFactors shifting demand:\n- Consumer income\n- Prices of related goods (substitutes and complements)\n- Consumer preferences\n- Expectations\n- Number of buyers\n\nSupply: quantity producers are willing to sell at various prices.\nLaw of Supply: As price rises, quantity supplied rises. Supply curve slopes upward.\n\nFactors shifting supply:\n- Input costs\n- Technology\n- Number of sellers\n- Government taxes and subsidies"},
    {"title":"Market Equilibrium",
     "content":"Equilibrium: price where quantity demanded = quantity supplied. The market clears.\n\nSurplus (excess supply): price above P* → quantity supplied > demanded → price falls.\nShortage (excess demand): price below P* → quantity demanded > supplied → price rises.\n\nEffects on equilibrium:\n- Demand increases → P* rises, Q* rises\n- Demand decreases → P* falls, Q* falls\n- Supply increases → P* falls, Q* rises\n- Supply decreases → P* rises, Q* falls\n\nPrice controls:\n- Price ceiling (below P*): causes shortage (e.g. rent control)\n- Price floor (above P*): causes surplus (e.g. minimum wage)"},
    {"title":"National Income",
     "content":"GDP = C + I + G + (X - M)\nC = household consumption\nI = business investment\nG = government spending\nX = exports, M = imports\n\nThree approaches:\n1. Expenditure: sum all spending on final goods.\n2. Income: sum all incomes earned.\n3. Output: sum value added at each production stage.\n\nNominal GDP: measured at current prices.\nReal GDP: adjusted for inflation using base year — measures actual output growth.\n\nGDP per capita = GDP ÷ population — used to compare living standards.\nEconomic growth: real GDP increases over time."},
  ],
  quiz_questions=[
    {"text":"According to the Law of Demand, what happens to quantity demanded when price rises?","options":["It rises","It falls","It stays the same","It doubles"],"correctIndex":1},
    {"text":"At equilibrium, quantity demanded equals quantity supplied.","options":["True","False"],"correctIndex":0},
    {"text":"A price ceiling set ABOVE the equilibrium price causes a shortage.","options":["True","False"],"correctIndex":1},
    {"text":"In the expenditure approach to GDP, I stands for:","options":["Imports","Investment","Income","Inflation"],"correctIndex":1},
    {"text":"If supply increases (shifts right), what happens to equilibrium price?","options":["It rises","It falls","It stays the same","It becomes negative"],"correctIndex":1},
  ],
  exercise_instruction="Qd = 100 - 2P and Qs = 20 + 3P. Find equilibrium price and quantity by setting Qd = Qs and solving for P, then substitute to find Q.",
  starter_code="# Qd = 100 - 2P\n# Qs = 20 + 3P\n# At equilibrium: Qd = Qs\n# Step 1: 100 - 2P = 20 + 3P\n# Step 2: Solve for P\n# Step 3: Substitute to find Q",
)

seed_course("WEBD101",
  lessons=[
    {"title":"HTML Basics",
     "content":"HTML (HyperText Markup Language) is the skeleton of every web page.\n\nDocument structure:\n<!DOCTYPE html>\n<html lang='en'>\n<head><meta charset='UTF-8'><title>Page</title></head>\n<body><!-- content --></body>\n</html>\n\nKey elements:\n- h1-h6: Headings\n- p: Paragraph\n- a href='url': Link\n- img src='url' alt='desc': Image\n- ul/ol/li: Lists\n- div: Block container\n- span: Inline container\n- form, input, button: Forms\n\nSemantic HTML: header, nav, main, section, article, footer\nAlways use semantic elements — they help screen readers and SEO.",
     "code":"<!DOCTYPE html>\n<html lang='en'>\n<head><meta charset='UTF-8'><title>My First Page</title></head>\n<body>\n  <header><h1>Welcome to Web Dev</h1></header>\n  <main>\n    <section>\n      <h2>About</h2>\n      <p>Learn HTML, CSS, JavaScript and PHP.</p>\n      <ul><li>Build pages</li><li>Style with CSS</li><li>Make interactive with JS</li></ul>\n    </section>\n  </main>\n  <footer><p>&copy; 2025 Tutorial</p></footer>\n</body>\n</html>"},
    {"title":"CSS Basics: Flexbox, Grid and Responsive Design",
     "content":"CSS controls the visual presentation of HTML.\n\nBox model: content → padding → border → margin\n\nFlexbox (1D):\n.container { display: flex; gap: 1rem; flex-wrap: wrap; }\n.item { flex: 1 1 200px; }\n\nCSS Grid (2D):\n.grid { display: grid; grid-template-columns: repeat(3, 1fr); gap: 1rem; }\n\nResponsive with media queries:\n@media (min-width: 768px) { .card { width: 48%; } }\n\nCSS variables:\n:root { --primary: #2563eb; }\nbutton { background: var(--primary); }",
     "code":"<!DOCTYPE html>\n<html lang='en'>\n<head><meta charset='UTF-8'><style>\n:root { --primary: #2563eb; }\nbody { font-family: sans-serif; padding: 1rem; }\n.flex-row { display: flex; gap: 1rem; flex-wrap: wrap; }\n.card { flex: 1 1 200px; padding: 1rem; border: 1px solid #ddd; border-radius: .5rem; }\nh2 { color: var(--primary); }\n</style></head>\n<body>\n  <div class='flex-row'>\n    <div class='card'><h2>Card 1</h2><p>Flexbox layout</p></div>\n    <div class='card'><h2>Card 2</h2><p>Responsive cards</p></div>\n    <div class='card'><h2>Card 3</h2><p>Try resizing!</p></div>\n  </div>\n</body></html>"},
    {"title":"JavaScript Basics: DOM, Events and Fetch",
     "content":"JavaScript makes web pages interactive.\n\nSelecting elements:\nconst btn = document.getElementById('myBtn');\nbtn.textContent = 'Hello!';  // safe — no XSS\n\nEvents:\nbtn.addEventListener('click', () => { console.log('clicked!'); });\n\nFetch API:\nasync function loadData() {\n  const res  = await fetch('/api/courses');\n  const json = await res.json();\n  json.data.forEach(c => {\n    const li = document.createElement('li');\n    li.textContent = c.title;  // textContent, not innerHTML!\n    document.getElementById('list').appendChild(li);\n  });\n}\n\nES6 features:\n- const/let (prefer const)\n- Arrow functions: (x) => x * 2\n- Template literals: `Hello ${name}!`\n- Destructuring: const { title } = course;",
     "code":"<!DOCTYPE html>\n<html><head><meta charset='UTF-8'></head>\n<body>\n  <h2>Click Counter</h2>\n  <button id='btn'>Click me</button>\n  <p>Count: <span id='count'>0</span></p>\n  <input id='inp' placeholder='Type something' />\n  <button id='add'>Add to list</button>\n  <ul id='list'></ul>\n  <script>\n    let n = 0;\n    document.getElementById('btn').addEventListener('click', () => {\n      document.getElementById('count').textContent = ++n;\n    });\n    document.getElementById('add').addEventListener('click', () => {\n      const val = document.getElementById('inp').value.trim();\n      if (!val) return;\n      const li = document.createElement('li');\n      li.textContent = val;  // safe\n      document.getElementById('list').appendChild(li);\n      document.getElementById('inp').value = '';\n    });\n  </script>\n</body></html>"},
    {"title":"PHP Basics: Forms, Sessions and REST API",
     "content":"PHP is a server-side scripting language for building REST APIs.\n\nReading JSON input:\n$body  = json_decode(file_get_contents('php://input'), true);\n$email = filter_var($body['email'] ?? '', FILTER_VALIDATE_EMAIL);\n\nPassword security:\n$hash  = password_hash($plain, PASSWORD_BCRYPT);  // register\n$valid = password_verify($plain, $hash);           // login\n\nSending JSON response:\nheader('Content-Type: application/json');\necho json_encode(['success' => true, 'data' => $result]);\nexit;\n\nMongoDB connection:\n$client = new MongoDB\\Client(getenv('MONGODB_URI'));\n$db     = $client->tutorialdb;\n$user   = $db->users->findOne(['email' => $email]);"},
    {"title":"Mini Project: Personal To-Do App",
     "content":"Build a to-do app applying all you have learned:\n\nRequirements:\n1. Text input + Add button\n2. Tasks appear in a list with Done checkbox and Delete button\n3. Completed tasks are crossed out\n4. Task list survives page reload (localStorage)\n\nTips:\n- Use textContent when inserting user text (never innerHTML)\n- Keep CSS in a <style> block\n- Test on a narrow viewport for responsiveness",
     "code":"<!DOCTYPE html>\n<html lang='en'>\n<head><meta charset='UTF-8'><style>\nbody { font-family: sans-serif; max-width: 500px; margin: 2rem auto; padding: 1rem; }\n.task { display: flex; align-items: center; gap: .5rem; margin: .5rem 0; }\n.task.done span { text-decoration: line-through; color: #888; }\ninput[type=text] { flex: 1; padding: .4rem; }\nbutton { padding: .4rem .8rem; cursor: pointer; }\n</style></head>\n<body>\n  <h1>To-Do List</h1>\n  <div style='display:flex;gap:.5rem'>\n    <input type='text' id='inp' placeholder='New task...' />\n    <button id='addBtn'>Add</button>\n  </div>\n  <ul id='list'></ul>\n  <script>\n    let tasks = JSON.parse(localStorage.getItem('tasks') || '[]');\n    const save = () => localStorage.setItem('tasks', JSON.stringify(tasks));\n    function render() {\n      const list = document.getElementById('list');\n      list.textContent = '';\n      tasks.forEach((t, i) => {\n        const li = document.createElement('li');\n        li.className = 'task' + (t.done ? ' done' : '');\n        const cb = document.createElement('input');\n        cb.type = 'checkbox'; cb.checked = t.done;\n        cb.onchange = () => { tasks[i].done = cb.checked; save(); render(); };\n        const sp = document.createElement('span');\n        sp.textContent = t.text;\n        const del = document.createElement('button');\n        del.textContent = 'X';\n        del.onclick = () => { tasks.splice(i,1); save(); render(); };\n        li.append(cb, sp, del);\n        list.appendChild(li);\n      });\n    }\n    document.getElementById('addBtn').onclick = () => {\n      const v = document.getElementById('inp').value.trim();\n      if (!v) return;\n      tasks.push({text: v, done: false});\n      document.getElementById('inp').value = '';\n      save(); render();\n    };\n    render();\n  </script>\n</body></html>"},
  ],
  quiz_questions=[
    {"text":"Which HTML attribute provides fallback text for an image?","options":["src","title","alt","href"],"correctIndex":2},
    {"text":"In CSS Flexbox, which property makes items wrap to a new line?","options":["flex-wrap: wrap","flex-direction: column","overflow: auto","display: grid"],"correctIndex":0},
    {"text":"Using textContent instead of innerHTML prevents XSS attacks.","options":["True","False"],"correctIndex":0},
    {"text":"Which PHP function correctly hashes a password?","options":["md5($pass)","sha1($pass)","password_hash($pass, PASSWORD_BCRYPT)","encrypt($pass)"],"correctIndex":2},
    {"text":"Which keyword waits for a fetch() promise to resolve?","options":["then()","await","callback()","sync()"],"correctIndex":1},
  ],
  exercise_instruction="Complete the to-do app: add a 'Complete All' button that marks all tasks as done and saves to localStorage.",
  starter_code="// Add a 'Complete All' button in the HTML\n// Then add an event listener:\n// 1. Set tasks[i].done = true for every task\n// 2. Call save() and render()",
)

print("\n✓ Seed complete!")
client.close()
