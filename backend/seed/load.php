<?php

/**
 * Seed script — run once to populate MongoDB.
 *
 *   php seed/load.php
 *
 * Safe to run multiple times: courses are upserted by code, lessons/quizzes/exercises
 * are inserted only if none exist yet for that course.
 */

declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';

use Dotenv\Dotenv;
use MongoDB\Client;
use MongoDB\BSON\UTCDateTime;

// ── Load env ─────────────────────────────────────────────────────────────
Dotenv::createImmutable(__DIR__ . '/..')->safeLoad();

$uri    = getenv('MONGODB_URI') ?: ($_ENV['MONGODB_URI'] ?? '');
$dbName = getenv('DB_NAME')     ?: ($_ENV['DB_NAME']     ?? 'tutorialdb');

if ($uri === '') {
    fwrite(STDERR, "ERROR: MONGODB_URI is not set. Copy .env.example to .env and fill it in.\n");
    exit(1);
}

$client = new Client($uri);
$db     = $client->selectDatabase($dbName);

echo "Connected to $dbName.\n";

// ── 1. Create indexes ─────────────────────────────────────────────────────
echo "\nCreating indexes...\n";

$db->users->createIndex(['email' => 1], ['unique' => true, 'name' => 'users_email_unique']);
$db->courses->createIndex(['streams' => 1], ['name' => 'courses_streams']);
$db->lessons->createIndex(['courseId' => 1, 'order' => 1], ['name' => 'lessons_courseId_order']);
$db->enrollments->createIndex(
    ['studentId' => 1, 'courseId' => 1],
    ['unique' => true, 'name' => 'enrollments_student_course_unique']
);
$db->quizAttempts->createIndex(['studentId' => 1], ['name' => 'quizAttempts_studentId']);
$db->loginAttempts->createIndex(['key' => 1, 'createdAt' => 1], ['name' => 'loginAttempts_key_date']);
// TTL: auto-delete login attempt records after 1 hour
$db->loginAttempts->createIndex(
    ['createdAt' => 1],
    ['expireAfterSeconds' => 3600, 'name' => 'loginAttempts_ttl']
);

echo "Indexes created.\n";

// ── 2. Upsert courses ─────────────────────────────────────────────────────
echo "\nUpserting courses...\n";

$coursesJson = file_get_contents(__DIR__ . '/courses.json');
$courseDefs  = json_decode((string) $coursesJson, true);

$courseMap   = []; // code → inserted/existing _id
$upserted    = 0;

foreach ($courseDefs as $def) {
    $result = $db->courses->findOneAndUpdate(
        ['code' => $def['code']],
        ['$setOnInsert' => [
            'code'         => $def['code'],
            'title'        => $def['title'],
            'description'  => '',
            'streams'      => $def['streams'],
            'group'        => $def['group'],
            'level'        => 'beginner',
            'instructorId' => null,
            'isPublished'  => true,
            'createdAt'    => new UTCDateTime(),
        ]],
        ['upsert' => true, 'returnDocument' => \MongoDB\Operation\FindOneAndUpdate::RETURN_DOCUMENT_AFTER]
    );
    $courseMap[$def['code']] = $result['_id'];
    $upserted++;
}

echo "  $upserted courses upserted.\n";

// ── 3. Helper: insert sample content only if not yet present ──────────────

function insertSampleContent(
    \MongoDB\Database $db,
    mixed $courseId,
    array $lessons,
    array $quizQuestions,
    string $exerciseInstruction,
    string $exerciseStarterCode = ''
): void {
    // Skip if lessons already exist for this course
    if ($db->lessons->countDocuments(['courseId' => $courseId]) > 0) {
        echo "    (already seeded — skipping)\n";
        return;
    }

    $order = 1;
    $lessonIds = [];
    foreach ($lessons as $l) {
        $result     = $db->lessons->insertOne([
            'courseId'    => $courseId,
            'unit'        => 1,
            'title'       => $l['title'],
            'content'     => $l['content'],
            'codeExample' => $l['codeExample'] ?? '',
            'videoUrl'    => '',
            'order'       => $order++,
            'isFree'      => ($order === 2), // first lesson is free
        ]);
        $lessonIds[] = $result->getInsertedId();
    }

    // Exercise
    $db->exercises->insertOne([
        'courseId'    => $courseId,
        'lessonId'    => $lessonIds[0],
        'instruction' => $exerciseInstruction,
        'starterCode' => $exerciseStarterCode,
    ]);

    // Quiz
    $db->quizzes->insertOne([
        'courseId'  => $courseId,
        'lessonId'  => end($lessonIds),
        'title'     => 'End-of-Unit Quiz',
        'questions' => $quizQuestions,
    ]);

    echo '    ' . count($lessons) . " lessons, 1 exercise, 1 quiz inserted.\n";
}

// ── 4. Logic and Critical Thinking (LOCT101) ──────────────────────────────
echo "\nSeeding LOCT101 – Logic and Critical Thinking...\n";
insertSampleContent(
    $db,
    $courseMap['LOCT101'],
    [
        [
            'title'   => 'Arguments and Propositions',
            'content' => <<<TEXT
            An **argument** is a set of statements in which one statement (the conclusion) is claimed to follow from the others (the premises).

            A **proposition** is a statement that is either true or false. Examples:
            - "The sun rises in the east." — true proposition.
            - "2 + 2 = 5." — false proposition.

            **Types of arguments:**
            1. **Deductive** – the conclusion necessarily follows from the premises (validity is key).
            2. **Inductive** – the conclusion probably follows from the premises (strength is key).

            **Validity vs. Soundness**
            - A deductive argument is *valid* if the conclusion must be true whenever the premises are true.
            - It is *sound* if it is valid AND all premises are actually true.

            Example of a valid argument:
            - Premise 1: All humans are mortal.
            - Premise 2: Socrates is a human.
            - Conclusion: Therefore, Socrates is mortal.
            TEXT,
        ],
        [
            'title'   => 'Common Logical Fallacies',
            'content' => <<<TEXT
            A **fallacy** is an error in reasoning that makes an argument unsound. Recognising fallacies is a key critical-thinking skill.

            | Fallacy | Description | Example |
            |---------|-------------|---------|
            | Ad Hominem | Attacking the person instead of the argument | "You can't trust his climate data — he's not even a scientist." |
            | Straw Man | Misrepresenting someone's argument to attack it more easily | "She wants stricter gun laws, so she wants to ban all guns." |
            | False Dilemma | Presenting only two options when more exist | "You're either with us or against us." |
            | Slippery Slope | Claiming one event will inevitably lead to extreme consequences | "If we allow A, then Z will happen." |
            | Appeal to Authority | Using an authority figure as evidence without other support | "A famous actor says this diet works, so it must." |
            | Circular Reasoning | Using the conclusion as a premise | "The Bible is true because it says so in the Bible." |

            **How to spot a fallacy:**
            1. Identify the claim being made.
            2. Examine the evidence provided.
            3. Ask whether the evidence actually supports the claim.
            TEXT,
        ],
        [
            'title'   => 'Deductive vs. Inductive Reasoning',
            'content' => <<<TEXT
            **Deductive reasoning** moves from general principles to a specific conclusion. If the premises are true and the argument is valid, the conclusion is guaranteed.

            Example:
            - All metals conduct electricity. (general)
            - Copper is a metal. (specific instance)
            - Therefore, copper conducts electricity. (conclusion — certain)

            **Inductive reasoning** moves from specific observations to a general conclusion. The conclusion is probable, not guaranteed.

            Example:
            - Every crow I have observed is black.
            - Therefore, all crows are black. (probable but not certain — there are white crows)

            **Key differences:**

            | | Deductive | Inductive |
            |-|-----------|-----------|
            | Direction | General → Specific | Specific → General |
            | Certainty | Guaranteed (if valid + sound) | Probable |
            | Can the conclusion be false if premises are true? | No (in a valid argument) | Yes |
            | Used in | Mathematics, formal logic | Science, everyday reasoning |

            **Abductive reasoning** (a third type) selects the simplest and most likely explanation for observations — used widely in medical diagnosis and detective work.
            TEXT,
        ],
    ],
    /* quiz */ [
        ['text' => 'A deductive argument guarantees its conclusion when the premises are true and the argument is valid.', 'options' => ['True', 'False'], 'correctIndex' => 0],
        ['text' => 'Which fallacy misrepresents an opponent\'s position to make it easier to attack?', 'options' => ['Ad Hominem', 'Straw Man', 'Slippery Slope', 'False Dilemma'], 'correctIndex' => 1],
        ['text' => 'Inductive reasoning moves from general principles to a specific conclusion.', 'options' => ['True', 'False'], 'correctIndex' => 1],
        ['text' => 'An argument is sound if it is valid AND all its premises are true.', 'options' => ['True', 'False'], 'correctIndex' => 0],
        ['text' => 'Which of these is an example of a false dilemma?', 'options' => ['"You must be a genius to solve that."', '"Either you support the policy or you hate the country."', '"She said it, so it must be wrong."', '"One small step leads to total chaos."'], 'correctIndex' => 1],
    ],
    'Identify the type of argument (deductive or inductive) and check whether it contains a fallacy. Write a brief justification for each of the following three arguments:\n1. All prime numbers greater than 2 are odd. 17 is a prime number greater than 2. Therefore 17 is odd.\n2. Every student I have tutored who studied daily passed the exam. So all students who study daily pass the exam.\n3. You should not listen to her advice on nutrition — she is overweight.'
);

// ── 5. Mathematics for Natural Sciences (MATH101) ─────────────────────────
echo "\nSeeding MATH101 – Mathematics for Natural Sciences...\n";
insertSampleContent(
    $db,
    $courseMap['MATH101'],
    [
        [
            'title'   => 'Functions',
            'content' => <<<TEXT
            A **function** $$f: A \to B$$ assigns to each element $$x \in A$$ exactly one element $$f(x) \in B$$.

            **Domain, codomain, and range**
            - **Domain**: the set of all valid inputs.
            - **Codomain**: the set the function maps into.
            - **Range**: the set of all actual outputs: $$\{f(x) \mid x \in A\}$$.

            **Common function types:**

            | Type | Form | Example |
            |------|------|---------|
            | Linear | $$f(x)=mx+b$$ | $$f(x)=3x+2$$ |
            | Quadratic | $$f(x)=ax^2+bx+c$$ | $$f(x)=x^2-4$$ |
            | Exponential | $$f(x)=a^x$$ | $$f(x)=2^x$$ |
            | Logarithmic | $$f(x)=\log_a x$$ | $$f(x)=\ln x$$ |

            **Even and odd functions:**
            - *Even*: $$f(-x)=f(x)$$ (symmetric about the y-axis). Example: $$f(x)=x^2$$.
            - *Odd*: $$f(-x)=-f(x)$$ (symmetric about the origin). Example: $$f(x)=x^3$$.

            **Composition:** $$(f \circ g)(x)=f(g(x))$$. Apply $$g$$ first, then $$f$$.

            **Inverse:** $$f^{-1}$$ satisfies $$f(f^{-1}(x))=x$$. A function has an inverse if and only if it is one-to-one (injective).
            TEXT,
        ],
        [
            'title'   => 'Limits and Continuity',
            'content' => <<<TEXT
            The **limit** $$\lim_{x \to a} f(x) = L$$ means: as $$x$$ gets arbitrarily close to $$a$$, $$f(x)$$ gets arbitrarily close to $$L$$.

            **Key limit laws:** for limits that exist,
            $$\lim_{x \to a}[f(x)+g(x)] = \lim_{x \to a}f(x) + \lim_{x \to a}g(x)$$

            **Important special limit:**
            $$\lim_{x \to 0}\frac{\sin x}{x} = 1$$

            This is proved using the squeeze theorem and is fundamental in calculus.

            **One-sided limits:**
            - Left-hand limit: $$\lim_{x \to a^-} f(x)$$
            - Right-hand limit: $$\lim_{x \to a^+} f(x)$$
            - The two-sided limit exists iff the one-sided limits are equal.

            **Continuity at a point $$a$$:** a function $$f$$ is continuous at $$a$$ if:
            1. $$f(a)$$ is defined.
            2. $$\lim_{x \to a} f(x)$$ exists.
            3. $$\lim_{x \to a} f(x) = f(a)$$.

            Polynomials and rational functions (away from their zeros) are continuous everywhere on their domain.
            TEXT,
        ],
        [
            'title'   => 'Derivatives',
            'content' => <<<TEXT
            The **derivative** of $$f$$ at $$x$$ is defined as:
            $$f'(x) = \lim_{h \to 0} \frac{f(x+h)-f(x)}{h}$$

            It measures the instantaneous rate of change of $$f$$ at $$x$$ (the slope of the tangent line).

            **Basic derivative rules:**

            | Rule | Formula |
            |------|---------|
            | Power rule | $$\frac{d}{dx}[x^n] = nx^{n-1}$$ |
            | Constant multiple | $$\frac{d}{dx}[cf(x)] = cf'(x)$$ |
            | Sum/difference | $$\frac{d}{dx}[f \pm g] = f' \pm g'$$ |
            | Product rule | $$\frac{d}{dx}[fg] = f'g + fg'$$ |
            | Quotient rule | $$\frac{d}{dx}\left[\frac{f}{g}\right] = \frac{f'g - fg'}{g^2}$$ |
            | Chain rule | $$\frac{d}{dx}[f(g(x))] = f'(g(x)) \cdot g'(x)$$ |

            **Common derivatives:**
            - $$\frac{d}{dx}[\sin x] = \cos x$$
            - $$\frac{d}{dx}[\cos x] = -\sin x$$
            - $$\frac{d}{dx}[e^x] = e^x$$
            - $$\frac{d}{dx}[\ln x] = \frac{1}{x}$$

            **Applications:** finding maximum/minimum values (set $$f'(x)=0$$), velocity from position, optimisation problems.
            TEXT,
        ],
    ],
    /* quiz */ [
        ['text' => 'What is the value of $$\\lim_{x \\to 0}\\frac{\\sin x}{x}$$?', 'options' => ['0', '1', 'undefined', '∞'], 'correctIndex' => 1],
        ['text' => 'Using the power rule, what is the derivative of $$x^5$$?', 'options' => ['$$5x^4$$', '$$5x^5$$', '$$x^4$$', '$$4x^5$$'], 'correctIndex' => 0],
        ['text' => 'A function is continuous at a point if its limit exists there, even if the function is not defined at that point.', 'options' => ['True', 'False'], 'correctIndex' => 1],
        ['text' => 'What is $$\\frac{d}{dx}[\\sin x]$$?', 'options' => ['$$-\\cos x$$', '$$\\cos x$$', '$$\\tan x$$', '$$-\\sin x$$'], 'correctIndex' => 1],
        ['text' => 'Which rule is used to differentiate $$f(g(x))$$?', 'options' => ['Product rule', 'Quotient rule', 'Chain rule', 'Power rule'], 'correctIndex' => 2],
    ],
    'Given $$f(x) = 3x^3 - 5x^2 + 2x - 7$$, find $$f\'(x)$$ using the basic differentiation rules. Then find the value of $$x$$ where $$f\'(x) = 0$$ (set up the equation; you do not need to solve it numerically).',
    '// Steps:\n// 1. Apply the power rule term by term\n// f\'(x) = ?\n// 2. Set f\'(x) = 0 and write the resulting equation'
);

// ── 6. Economics (ECON101) ────────────────────────────────────────────────
echo "\nSeeding ECON101 – Economics...\n";
insertSampleContent(
    $db,
    $courseMap['ECON101'],
    [
        [
            'title'   => 'Demand and Supply',
            'content' => <<<TEXT
            **Demand** is the quantity of a good or service that consumers are willing and able to buy at various prices in a given time period.

            **Law of Demand:** As the price of a good rises (ceteris paribus), the quantity demanded falls. The demand curve slopes downward.

            **Factors that shift the demand curve (non-price determinants):**
            - Income of consumers
            - Prices of related goods (substitutes and complements)
            - Consumer tastes and preferences
            - Expectations of future prices
            - Number of buyers in the market

            **Supply** is the quantity a producer is willing and able to sell at various prices.

            **Law of Supply:** As the price rises (ceteris paribus), quantity supplied rises. The supply curve slopes upward.

            **Factors that shift the supply curve:**
            - Cost of inputs (labour, raw materials)
            - Technology improvements
            - Number of sellers
            - Government taxes and subsidies
            - Expectations of future prices
            TEXT,
        ],
        [
            'title'   => 'Market Equilibrium',
            'content' => <<<TEXT
            **Equilibrium** is the price at which quantity demanded equals quantity supplied. At this price the market clears — there is no surplus or shortage.

            **Equilibrium price (P*)** and **equilibrium quantity (Q*)** are found at the intersection of the demand and supply curves.

            **Surplus (excess supply):** when price is above P*, quantity supplied > quantity demanded. Price falls back toward equilibrium.

            **Shortage (excess demand):** when price is below P*, quantity demanded > quantity supplied. Price rises back toward equilibrium.

            **Changes in equilibrium:**
            | Change | Effect on P* | Effect on Q* |
            |--------|-------------|-------------|
            | Demand increases (D shifts right) | Rises | Rises |
            | Demand decreases (D shifts left) | Falls | Falls |
            | Supply increases (S shifts right) | Falls | Rises |
            | Supply decreases (S shifts left) | Rises | Falls |

            **Price controls:**
            - **Price ceiling** (set below P*): causes a shortage (e.g. rent control).
            - **Price floor** (set above P*): causes a surplus (e.g. minimum wage).
            TEXT,
        ],
        [
            'title'   => 'National Income',
            'content' => <<<TEXT
            **National income** measures the total value of goods and services produced in an economy over a period, typically one year.

            **Gross Domestic Product (GDP)** — the most common measure:
            $$\text{GDP} = C + I + G + (X - M)$$
            where:
            - $$C$$ = household consumption
            - $$I$$ = business investment
            - $$G$$ = government spending
            - $$X$$ = exports, $$M$$ = imports

            **Three approaches to measuring GDP:**
            1. **Expenditure approach:** sum all spending on final goods and services (= formula above).
            2. **Income approach:** sum all incomes earned (wages, profits, rents, interest).
            3. **Output (production) approach:** sum the value added at each stage of production.

            **Nominal vs. Real GDP:**
            - *Nominal GDP* is measured at current prices.
            - *Real GDP* adjusts for inflation using a base year — it measures actual output growth.

            **GDP per capita** = GDP ÷ population — used to compare living standards across countries.

            **Economic growth** occurs when real GDP increases from one period to the next.
            TEXT,
        ],
    ],
    /* quiz */ [
        ['text' => 'According to the Law of Demand, what happens to quantity demanded when price rises?', 'options' => ['It rises', 'It falls', 'It stays the same', 'It doubles'], 'correctIndex' => 1],
        ['text' => 'At equilibrium, quantity demanded equals quantity supplied.', 'options' => ['True', 'False'], 'correctIndex' => 0],
        ['text' => 'A price ceiling set ABOVE the equilibrium price causes a shortage.', 'options' => ['True', 'False'], 'correctIndex' => 1],
        ['text' => 'In the expenditure approach to GDP, what does the letter I represent?', 'options' => ['Imports', 'Investment', 'Income', 'Inflation'], 'correctIndex' => 1],
        ['text' => 'If supply increases (shifts right) and demand stays the same, what happens to the equilibrium price?', 'options' => ['It rises', 'It falls', 'It stays the same', 'It becomes negative'], 'correctIndex' => 1],
    ],
    'Suppose the demand function for a product is Qd = 100 - 2P and the supply function is Qs = 20 + 3P. Find the equilibrium price and quantity by setting Qd = Qs and solving for P, then substituting to find Q.',
    '// Qd = 100 - 2P\n// Qs = 20 + 3P\n// At equilibrium: Qd = Qs\n// Step 1: Set 100 - 2P = 20 + 3P\n// Step 2: Solve for P\n// Step 3: Substitute P back to find Q'
);

// ── 7. Web Development (WEBD101) ──────────────────────────────────────────
echo "\nSeeding WEBD101 – Web Development...\n";
insertSampleContent(
    $db,
    $courseMap['WEBD101'],
    [
        [
            'title'      => 'HTML Basics',
            'content'    => <<<TEXT
            **HTML (HyperText Markup Language)** is the skeleton of every web page. It describes the structure and meaning of content using **elements** wrapped in angle-bracket tags.

            **Document structure:**
            Every HTML page follows this skeleton:
            ```html
            <!DOCTYPE html>
            <html lang="en">
            <head>
              <meta charset="UTF-8">
              <meta name="viewport" content="width=device-width, initial-scale=1.0">
              <title>Page Title</title>
            </head>
            <body>
              <!-- visible content goes here -->
            </body>
            </html>
            ```

            **Essential elements:**
            | Element | Purpose |
            |---------|---------|
            | `<h1>`–`<h6>` | Headings (h1 = most important) |
            | `<p>` | Paragraph |
            | `<a href="url">` | Hyperlink |
            | `<img src="url" alt="description">` | Image |
            | `<ul>` / `<ol>` / `<li>` | Unordered / ordered list / item |
            | `<div>` | Generic block container |
            | `<span>` | Generic inline container |
            | `<form>`, `<input>`, `<button>` | Forms and controls |

            **Semantic HTML** uses elements that describe their meaning: `<header>`, `<nav>`, `<main>`, `<section>`, `<article>`, `<footer>`.
            Always use semantic elements where appropriate — they help screen readers and SEO.
            TEXT,
            'codeExample' => '<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <title>My First Page</title>
</head>
<body>
  <header>
    <h1>Welcome to Web Development</h1>
    <nav>
      <a href="#about">About</a> |
      <a href="#contact">Contact</a>
    </nav>
  </header>
  <main>
    <section id="about">
      <h2>About This Course</h2>
      <p>In this track you will learn HTML, CSS, JavaScript and PHP.</p>
      <ul>
        <li>Build web pages</li>
        <li>Style them with CSS</li>
        <li>Make them interactive with JS</li>
      </ul>
    </section>
  </main>
  <footer>
    <p>&copy; 2025 Freshman Tutorial Platform</p>
  </footer>
</body>
</html>',
        ],
        [
            'title'      => 'CSS Basics: Flexbox, Grid and Responsive Design',
            'content'    => <<<TEXT
            **CSS (Cascading Style Sheets)** controls the visual presentation of HTML.

            **Selectors and the box model:**
            Every element is a box: `content → padding → border → margin`.

            **Flexbox** — one-dimensional layout (row or column):
            ```css
            .container { display: flex; gap: 1rem; flex-wrap: wrap; }
            .item { flex: 1 1 200px; }   /* grow, shrink, basis */
            ```

            **CSS Grid** — two-dimensional layout:
            ```css
            .grid { display: grid; grid-template-columns: repeat(3, 1fr); gap: 1rem; }
            ```

            **Responsive design with media queries:**
            ```css
            /* Default: mobile */
            .card { width: 100%; }
            /* Tablet and above */
            @media (min-width: 768px) { .card { width: 48%; } }
            /* Desktop */
            @media (min-width: 1024px) { .card { width: 30%; } }
            ```

            **CSS custom properties (variables):**
            ```css
            :root { --primary: #2563eb; --radius: 0.5rem; }
            button { background: var(--primary); border-radius: var(--radius); }
            ```
            TEXT,
            'codeExample' => '<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>CSS Layout Demo</title>
<style>
  :root { --primary: #2563eb; --gap: 1rem; }
  body { font-family: sans-serif; margin: 0; padding: var(--gap); }
  .flex-row { display: flex; gap: var(--gap); flex-wrap: wrap; }
  .card {
    flex: 1 1 200px;
    padding: 1rem;
    border: 1px solid #ddd;
    border-radius: 0.5rem;
    background: #f9fafb;
  }
  h2 { color: var(--primary); }
</style>
</head>
<body>
  <h1>Flexbox Card Layout</h1>
  <div class="flex-row">
    <div class="card"><h2>Card 1</h2><p>Flexbox distributes space evenly.</p></div>
    <div class="card"><h2>Card 2</h2><p>Cards wrap on small screens.</p></div>
    <div class="card"><h2>Card 3</h2><p>Try resizing the preview!</p></div>
  </div>
</body>
</html>',
        ],
        [
            'title'      => 'JavaScript Basics: DOM, Events and Fetch',
            'content'    => <<<TEXT
            **JavaScript** makes web pages interactive. It can read and modify the HTML/CSS in real time through the **DOM (Document Object Model)**.

            **Selecting and modifying elements:**
            ```javascript
            const heading = document.getElementById('title');
            heading.textContent = 'Hello World!';  // safe — no XSS risk

            const btn = document.querySelector('.submit-btn');
            btn.classList.add('active');
            ```

            **Events:**
            ```javascript
            btn.addEventListener('click', () => {
              console.log('Button clicked!');
            });
            ```

            **Fetch API (calling a REST endpoint):**
            ```javascript
            async function loadCourses() {
              const res  = await fetch('/api/courses');
              const json = await res.json();
              json.data.forEach(course => {
                const li = document.createElement('li');
                li.textContent = course.title;   // textContent, not innerHTML
                document.getElementById('list').appendChild(li);
              });
            }
            loadCourses();
            ```

            **Key ES6+ features:**
            - `const` / `let` (block scope, prefer `const`)
            - Arrow functions: `(x) => x * 2`
            - Template literals: `` `Hello ${name}!` ``
            - Destructuring: `const { title, code } = course;`
            - Spread: `const merged = [...arr1, ...arr2];`
            TEXT,
            'codeExample' => '<!DOCTYPE html>
<html lang="en">
<head><meta charset="UTF-8"><title>JS Events</title></head>
<body>
  <h2>Click Counter</h2>
  <button id="btn">Click me</button>
  <p>Count: <span id="count">0</span></p>

  <h2>Create a List Item</h2>
  <input id="inp" placeholder="Type something" />
  <button id="add">Add</button>
  <ul id="list"></ul>

  <script>
    let n = 0;
    document.getElementById("btn").addEventListener("click", () => {
      n++;
      document.getElementById("count").textContent = n;
    });

    document.getElementById("add").addEventListener("click", () => {
      const val = document.getElementById("inp").value.trim();
      if (!val) return;
      const li = document.createElement("li");
      li.textContent = val;           // safe — no innerHTML
      document.getElementById("list").appendChild(li);
      document.getElementById("inp").value = "";
    });
  </script>
</body>
</html>',
        ],
        [
            'title'      => 'PHP Basics: Forms, Sessions, REST API and MongoDB',
            'content'    => <<<TEXT
            **PHP** is a server-side scripting language ideal for building REST APIs.

            **Reading JSON input (for REST APIs):**
            ```php
            $body = json_decode(file_get_contents('php://input'), true);
            $email = filter_var($body['email'] ?? '', FILTER_VALIDATE_EMAIL);
            ```

            **Password security:**
            ```php
            $hash  = password_hash($plaintext, PASSWORD_BCRYPT);    // on register
            $valid = password_verify($plaintext, $hash);            // on login
            ```

            **Sessions:**
            ```php
            session_start();
            $_SESSION['userId'] = $userId;    // store after login
            $id = $_SESSION['userId'] ?? null; // read later
            ```

            **Sending a JSON response:**
            ```php
            header('Content-Type: application/json');
            echo json_encode(['success' => true, 'data' => $result]);
            exit;
            ```

            **Connecting to MongoDB Atlas:**
            ```php
            require 'vendor/autoload.php';
            $client = new MongoDB\Client(getenv('MONGODB_URI'));
            $db     = $client->tutorialdb;
            $user   = $db->users->findOne(['email' => $email]);
            ```
            TEXT,
            'codeExample' => '<?php
// Simple REST endpoint example (not a live demo — PHP runs on the server)
// This shows the pattern used throughout the Freshman Tutorial backend.

header("Content-Type: application/json");

$method = $_SERVER["REQUEST_METHOD"];
$body   = json_decode(file_get_contents("php://input"), true) ?? [];

if ($method === "POST") {
    $name = trim($body["name"] ?? "");
    if ($name === "") {
        http_response_code(422);
        echo json_encode(["success" => false, "message" => "name is required"]);
        exit;
    }
    // Save to DB here ...
    echo json_encode(["success" => true, "data" => ["name" => $name]]);
} else {
    http_response_code(405);
    echo json_encode(["success" => false, "message" => "Method not allowed"]);
}',
        ],
        [
            'title'      => 'Mini Project: Build a Personal To-Do App',
            'content'    => <<<TEXT
            Apply everything you have learned in a small end-to-end project. You will build a **personal to-do app** with:
            - An HTML form to add tasks.
            - CSS to make it look clean and responsive.
            - JavaScript to add, complete and delete tasks without reloading the page.
            - (Optional extension) Save and load tasks from a PHP/MongoDB backend.

            **Requirements:**
            1. A text input and an "Add" button.
            2. Tasks appear in a list; each task has a "Done" checkbox and a "Delete" button.
            3. Completed tasks are visually crossed out.
            4. The task list survives page reload (use `localStorage` for the basic version).

            **Tips:**
            - Use `textContent` when inserting user-typed text into the DOM (never `innerHTML`).
            - Keep your CSS in a separate `<style>` block or file.
            - Test on a narrow viewport to verify it is responsive.
            TEXT,
            'codeExample' => '<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>To-Do App</title>
<style>
  body { font-family: sans-serif; max-width: 500px; margin: 2rem auto; padding: 1rem; }
  .task { display: flex; align-items: center; gap: 0.5rem; margin: 0.5rem 0; }
  .task.done span { text-decoration: line-through; color: #888; }
  input[type=text] { flex: 1; padding: 0.4rem; font-size: 1rem; }
  button { padding: 0.4rem 0.8rem; cursor: pointer; }
</style>
</head>
<body>
  <h1>My To-Do List</h1>
  <div style="display:flex;gap:.5rem">
    <input type="text" id="inp" placeholder="New task…" />
    <button id="addBtn">Add</button>
  </div>
  <ul id="list"></ul>

  <script>
    const inp   = document.getElementById("inp");
    const list  = document.getElementById("list");
    let   tasks = JSON.parse(localStorage.getItem("tasks") || "[]");

    function save() { localStorage.setItem("tasks", JSON.stringify(tasks)); }

    function render() {
      list.textContent = "";
      tasks.forEach((t, i) => {
        const li  = document.createElement("li");
        li.className = "task" + (t.done ? " done" : "");
        const cb  = document.createElement("input");
        cb.type = "checkbox"; cb.checked = t.done;
        cb.addEventListener("change", () => { tasks[i].done = cb.checked; save(); render(); });
        const sp  = document.createElement("span");
        sp.textContent = t.text;                  // safe
        const del = document.createElement("button");
        del.textContent = "✕";
        del.addEventListener("click", () => { tasks.splice(i, 1); save(); render(); });
        li.append(cb, sp, del);
        list.appendChild(li);
      });
    }

    document.getElementById("addBtn").addEventListener("click", () => {
      const v = inp.value.trim();
      if (!v) return;
      tasks.push({ text: v, done: false });
      inp.value = "";
      save(); render();
    });

    render();
  </script>
</body>
</html>',
        ],
    ],
    /* quiz */ [
        ['text' => 'Which HTML attribute provides fallback text for an image when it cannot be displayed?', 'options' => ['src', 'title', 'alt', 'href'], 'correctIndex' => 2],
        ['text' => 'In CSS Flexbox, which property makes items wrap onto a new line when they overflow?', 'options' => ['flex-wrap: wrap', 'flex-direction: column', 'overflow: auto', 'display: grid'], 'correctIndex' => 0],
        ['text' => 'Using textContent instead of innerHTML is safer because it prevents XSS (cross-site scripting) attacks.', 'options' => ['True', 'False'], 'correctIndex' => 0],
        ['text' => 'Which PHP function correctly hashes a password for storage?', 'options' => ['md5($pass)', 'sha1($pass)', 'password_hash($pass, PASSWORD_BCRYPT)', 'encrypt($pass)'], 'correctIndex' => 2],
        ['text' => 'In the Fetch API, which method do you use to wait for an asynchronous response?', 'options' => ['then()', 'await', 'callback()', 'sync()'], 'correctIndex' => 1],
    ],
    'Complete the to-do app starter code: add the ability to mark all tasks as done with a single "Complete All" button, and persist the tasks in localStorage.',
    '// Starter: the render() and save() functions are already written above.\n// Add:\n// 1. A "Complete All" button in the HTML\n// 2. An event listener that sets tasks[i].done = true for every task\n// 3. Calls save() and render()'
);

// ── Done ──────────────────────────────────────────────────────────────────
echo "\n✓ Seed complete. Database: $dbName\n";
