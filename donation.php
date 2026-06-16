<?php
$errors = [];
$successMessage = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $name = trim($_POST['name'] ?? '');
    $email = trim($_POST['email'] ?? '');
    $amount = trim($_POST['amount'] ?? '');
    $projectType = trim($_POST['project_type'] ?? '');
    $message = trim($_POST['message'] ?? '');

    if ($name === '') {
        $errors[] = 'Please enter your name.';
    }

    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $errors[] = 'Please enter a valid email address.';
    }

    if (!is_numeric($amount) || (float) $amount < 5) {
        $errors[] = 'Minimum donation amount is $5.';
    }

    $allowedProjectTypes = ['Web App', 'Web Service API', 'SaaS Platform', 'Portfolio Website', 'Hosting Setup'];
    if (!in_array($projectType, $allowedProjectTypes, true)) {
        $errors[] = 'Please choose a valid project type.';
    }

    if (empty($errors)) {
        $safeName = htmlspecialchars($name, ENT_QUOTES, 'UTF-8');
        $safeProjectType = htmlspecialchars($projectType, ENT_QUOTES, 'UTF-8');
        $safeAmount = number_format((float) $amount, 2);

        $dataLine = sprintf(
            "%s,%s,%s,%s,%s,%s\n",
            date('c'),
            str_replace(',', ' ', $name),
            str_replace(',', ' ', $email),
            $safeAmount,
            str_replace(',', ' ', $projectType),
            str_replace(["\r", "\n", ','], ' ', $message)
        );

        @file_put_contents(__DIR__ . '/donations.csv', $dataLine, FILE_APPEND);

        $successMessage = "Thank you, {$safeName}! Your $${safeAmount} donation request for {$safeProjectType} was received.";

        $_POST = [];
    }
}
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Dev Donate | Hosting & Portfolio Solutions</title>
    <style>
        :root {
            --bg: #0b1220;
            --card: #121a2b;
            --primary: #3b82f6;
            --secondary: #22c55e;
            --text: #e2e8f0;
            --muted: #94a3b8;
            --danger: #ef4444;
        }

        * {
            box-sizing: border-box;
        }

        body {
            margin: 0;
            font-family: Inter, Arial, sans-serif;
            background: linear-gradient(165deg, #0b1220 20%, #121a2b 100%);
            color: var(--text);
            line-height: 1.5;
        }

        .container {
            width: min(1100px, 92%);
            margin: 0 auto;
        }

        header {
            padding: 3rem 0 2rem;
            border-bottom: 1px solid rgba(148, 163, 184, 0.18);
        }

        .hero {
            display: grid;
            gap: 1rem;
        }

        .badge {
            display: inline-block;
            width: fit-content;
            padding: .3rem .8rem;
            border-radius: 100px;
            background: rgba(59, 130, 246, 0.2);
            color: #bfdbfe;
            font-size: .85rem;
            letter-spacing: .04em;
        }

        h1 {
            margin: 0;
            font-size: clamp(1.8rem, 4vw, 3rem);
        }

        p {
            margin: 0;
            color: var(--muted);
        }

        .grid {
            margin-top: 2rem;
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(260px, 1fr));
            gap: 1rem;
        }

        .card {
            background: rgba(18, 26, 43, 0.85);
            border: 1px solid rgba(148, 163, 184, 0.18);
            border-radius: 16px;
            padding: 1.2rem;
            backdrop-filter: blur(4px);
        }

        .card h3 {
            margin: 0 0 .3rem;
            font-size: 1.1rem;
        }

        .price {
            color: var(--secondary);
            font-weight: 700;
            margin-top: .4rem;
        }

        .portfolio-list {
            margin: .6rem 0 0;
            padding-left: 1.1rem;
            color: var(--muted);
        }

        main {
            padding: 2rem 0 4rem;
        }

        form {
            display: grid;
            gap: .9rem;
            margin-top: 1rem;
        }

        label {
            font-size: .92rem;
            color: #cbd5e1;
            display: block;
            margin-bottom: .3rem;
        }

        input,
        select,
        textarea,
        button {
            width: 100%;
            border-radius: 10px;
            border: 1px solid rgba(148, 163, 184, 0.35);
            background: #0f172a;
            color: var(--text);
            padding: .75rem .8rem;
            font-size: .95rem;
        }

        textarea {
            resize: vertical;
            min-height: 110px;
        }

        button {
            cursor: pointer;
            background: linear-gradient(135deg, var(--primary), #1d4ed8);
            border: none;
            font-weight: 600;
            transition: transform .2s ease;
        }

        button:hover {
            transform: translateY(-1px);
        }

        .notice,
        .errors {
            margin-top: .9rem;
            border-radius: 10px;
            padding: .8rem .9rem;
        }

        .notice {
            border: 1px solid rgba(34, 197, 94, 0.5);
            background: rgba(34, 197, 94, 0.12);
            color: #bbf7d0;
        }

        .errors {
            border: 1px solid rgba(239, 68, 68, 0.5);
            background: rgba(239, 68, 68, 0.12);
            color: #fecaca;
        }

        footer {
            border-top: 1px solid rgba(148, 163, 184, 0.18);
            padding: 1.2rem 0 2rem;
            color: var(--muted);
            font-size: .88rem;
        }
    </style>
</head>
<body>
    <header>
        <div class="container hero">
            <span class="badge">Dev Funding + Hosting + Portfolio</span>
            <h1>Support Development of Web Apps, Services, and Hosting Solutions</h1>
            <p>Use this page to donate for custom web app creation, backend API services, hosting setup, or professional portfolio website development.</p>
        </div>
    </header>

    <main>
        <div class="container grid">
            <section class="card">
                <h3>Hosting Solution Provider</h3>
                <p>Production-ready infrastructure for apps and services:</p>
                <ul class="portfolio-list">
                    <li>Domain, SSL, and DNS setup</li>
                    <li>Cloud VPS / container deployment</li>
                    <li>Backups, monitoring, and uptime checks</li>
                </ul>
                <p class="price">Starting from $79</p>
            </section>

            <section class="card">
                <h3>Portfolio & App Projects</h3>
                <p>Crafted solutions for startups and personal brands:</p>
                <ul class="portfolio-list">
                    <li>Portfolio website design & development</li>
                    <li>Custom web application MVP</li>
                    <li>REST API and service integration</li>
                </ul>
                <p class="price">Starting from $149</p>
            </section>

            <section class="card">
                <h3>Send a Donation / Request</h3>
                <p>Share your goal and budget. You will receive a tailored development plan.</p>

                <?php if ($successMessage): ?>
                    <div class="notice"><?= $successMessage ?></div>
                <?php endif; ?>

                <?php if (!empty($errors)): ?>
                    <div class="errors">
                        <strong>Please fix the following:</strong>
                        <ul>
                            <?php foreach ($errors as $error): ?>
                                <li><?= htmlspecialchars($error, ENT_QUOTES, 'UTF-8') ?></li>
                            <?php endforeach; ?>
                        </ul>
                    </div>
                <?php endif; ?>

                <form method="post" action="">
                    <div>
                        <label for="name">Full name</label>
                        <input id="name" name="name" type="text" value="<?= htmlspecialchars($_POST['name'] ?? '', ENT_QUOTES, 'UTF-8') ?>" required>
                    </div>

                    <div>
                        <label for="email">Email</label>
                        <input id="email" name="email" type="email" value="<?= htmlspecialchars($_POST['email'] ?? '', ENT_QUOTES, 'UTF-8') ?>" required>
                    </div>

                    <div>
                        <label for="amount">Donation amount (USD)</label>
                        <input id="amount" name="amount" type="number" min="5" step="0.01" value="<?= htmlspecialchars($_POST['amount'] ?? '25', ENT_QUOTES, 'UTF-8') ?>" required>
                    </div>

                    <div>
                        <label for="project_type">Project type</label>
                        <select id="project_type" name="project_type" required>
                            <?php
                            $options = ['Web App', 'Web Service API', 'SaaS Platform', 'Portfolio Website', 'Hosting Setup'];
                            $selectedType = $_POST['project_type'] ?? 'Web App';
                            foreach ($options as $option):
                                $selected = $selectedType === $option ? 'selected' : '';
                            ?>
                                <option value="<?= htmlspecialchars($option, ENT_QUOTES, 'UTF-8') ?>" <?= $selected ?>>
                                    <?= htmlspecialchars($option, ENT_QUOTES, 'UTF-8') ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div>
                        <label for="message">Project message</label>
                        <textarea id="message" name="message" placeholder="Describe your app, service, or hosting needs."><?= htmlspecialchars($_POST['message'] ?? '', ENT_QUOTES, 'UTF-8') ?></textarea>
                    </div>

                    <button type="submit">Donate & Request Project</button>
                </form>
            </section>
        </div>
    </main>

    <footer>
        <div class="container">© <?= date('Y') ?> Dev Donate Solutions — Web Apps, Services, Hosting & Portfolio Development.</div>
    </footer>
</body>
</html>
