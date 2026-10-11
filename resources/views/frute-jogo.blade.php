<!doctype html>
<html lang="pt-BR">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Frute Jogo | KazaKora</title>
    <meta name="robots" content="noindex,nofollow">
    <style>
        :root {
            --cream: #fff6db;
            --banana: #ffd94d;
            --orange: #ff8b39;
            --berry: #f9417a;
            --grape: #7d3cff;
            --lime: #54d36b;
            --mint: #d9ff9f;
            --ink: #221407;
            --brown: #563013;
            --panel: rgba(255, 250, 224, .82);
            --glass: rgba(255, 255, 255, .18);
            --shadow: 0 24px 80px rgba(86, 48, 19, .34);
        }

        * { box-sizing: border-box; }

        html, body { min-height: 100%; }

        body {
            margin: 0;
            font-family: Inter, ui-sans-serif, system-ui, -apple-system, BlinkMacSystemFont, "Segoe UI", sans-serif;
            color: var(--ink);
            background:
                radial-gradient(circle at 16% 18%, rgba(255, 232, 102, .95) 0 10%, transparent 26%),
                radial-gradient(circle at 85% 12%, rgba(249, 65, 122, .78) 0 9%, transparent 24%),
                radial-gradient(circle at 74% 86%, rgba(84, 211, 107, .9) 0 12%, transparent 30%),
                linear-gradient(135deg, #ffef8a 0%, #ffb053 36%, #ff5d8f 68%, #7d3cff 100%);
            overflow-x: hidden;
        }

        body::before {
            content: "🍓 🍋 🍒 🍇 🍊 🍉 🍍 🍌 🍓 🍒 🍋 🍇 🍊 🍉 🍍 🍌";
            position: fixed;
            inset: -12vh -10vw auto -10vw;
            height: 130vh;
            z-index: -2;
            opacity: .20;
            font-size: clamp(34px, 7vw, 86px);
            line-height: 1.7;
            letter-spacing: .28em;
            transform: rotate(-14deg);
            filter: saturate(1.25);
            animation: fruitDrift 18s linear infinite;
            pointer-events: none;
        }

        body::after {
            content: "";
            position: fixed;
            inset: 0;
            z-index: -1;
            background:
                linear-gradient(rgba(255,255,255,.16) 1px, transparent 1px),
                linear-gradient(90deg, rgba(255,255,255,.13) 1px, transparent 1px);
            background-size: 42px 42px;
            mask-image: radial-gradient(circle at center, #000 0 58%, transparent 100%);
            pointer-events: none;
        }

        @keyframes fruitDrift {
            0% { translate: 0 0; }
            100% { translate: 0 130px; }
        }

        .shell {
            width: min(1180px, calc(100% - 28px));
            margin: 0 auto;
            padding: 18px 0 46px;
        }

        .navbar {
            position: sticky;
            top: 12px;
            z-index: 5;
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 14px;
            padding: 12px 14px 12px 18px;
            border: 1px solid rgba(255,255,255,.45);
            border-radius: 24px;
            background: rgba(255, 252, 234, .72);
            box-shadow: 0 16px 52px rgba(72, 30, 0, .18);
            backdrop-filter: blur(20px);
        }

        .brand {
            display: flex;
            align-items: center;
            gap: 12px;
            font-weight: 900;
            letter-spacing: -.04em;
        }

        .brand-mark {
            display: grid;
            place-items: center;
            width: 42px;
            height: 42px;
            border-radius: 16px;
            background: linear-gradient(135deg, #ffda54, #ff5d8f 58%, #7d3cff);
            box-shadow: inset 0 2px 8px rgba(255,255,255,.45), 0 10px 22px rgba(125,60,255,.22);
            font-size: 24px;
        }

        .nav-pill {
            display: flex;
            align-items: center;
            gap: 8px;
            padding: 10px 14px;
            border-radius: 999px;
            background: rgba(34, 20, 7, .08);
            color: rgba(34, 20, 7, .78);
            font-size: 13px;
            font-weight: 800;
        }

        .hero {
            display: grid;
            grid-template-columns: minmax(0, .96fr) minmax(330px, 1.04fr);
            gap: 26px;
            align-items: center;
            padding: 38px 0 22px;
        }

        .copy-card {
            padding: clamp(24px, 4vw, 42px);
            border: 1px solid rgba(255,255,255,.42);
            border-radius: 38px;
            background: linear-gradient(180deg, rgba(255, 254, 242, .86), rgba(255, 238, 179, .72));
            box-shadow: var(--shadow);
            backdrop-filter: blur(18px);
        }

        .eyebrow {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            padding: 8px 12px;
            border-radius: 999px;
            background: rgba(84, 211, 107, .18);
            color: #256336;
            font-weight: 900;
            font-size: 13px;
            text-transform: uppercase;
            letter-spacing: .08em;
        }

        h1 {
            margin: 18px 0 12px;
            max-width: 11ch;
            font-size: clamp(48px, 9vw, 108px);
            line-height: .82;
            letter-spacing: -.08em;
        }

        .lead {
            max-width: 58ch;
            margin: 0;
            color: rgba(34, 20, 7, .72);
            font-size: clamp(16px, 2vw, 20px);
            line-height: 1.6;
            font-weight: 650;
        }

        .disclaimer {
            margin-top: 18px;
            padding: 12px 14px;
            border: 1px dashed rgba(86,48,19,.26);
            border-radius: 18px;
            background: rgba(255,255,255,.38);
            color: rgba(86,48,19,.72);
            font-size: 13px;
            font-weight: 800;
        }

        .score-grid {
            display: grid;
            grid-template-columns: repeat(3, 1fr);
            gap: 12px;
            margin-top: 22px;
        }

        .metric {
            min-height: 106px;
            padding: 16px;
            border-radius: 24px;
            background: rgba(255, 255, 255, .48);
            border: 1px solid rgba(255,255,255,.42);
            box-shadow: inset 0 1px 0 rgba(255,255,255,.55);
        }

        .metric small {
            display: block;
            color: rgba(34, 20, 7, .55);
            font-size: 12px;
            font-weight: 900;
            text-transform: uppercase;
            letter-spacing: .06em;
        }

        .metric strong {
            display: block;
            margin-top: 9px;
            font-size: clamp(22px, 3vw, 34px);
            letter-spacing: -.06em;
        }

        .metric.loss strong { color: #bd254d; }
        .metric.win strong { color: #15803d; }

        .machine-wrap {
            position: relative;
        }

        .machine {
            position: relative;
            padding: 20px;
            border-radius: 42px;
            background:
                linear-gradient(160deg, rgba(255,255,255,.68), rgba(255,255,255,.18)),
                linear-gradient(135deg, #541f7a 0%, #f9417a 54%, #ffcb3d 100%);
            box-shadow: 0 30px 90px rgba(72, 30, 0, .34), inset 0 2px 0 rgba(255,255,255,.55);
            border: 1px solid rgba(255,255,255,.5);
            overflow: hidden;
        }

        .machine::before {
            content: "";
            position: absolute;
            inset: 14px;
            border-radius: 34px;
            border: 1px solid rgba(255,255,255,.22);
            pointer-events: none;
        }

        .machine-top {
            position: relative;
            z-index: 1;
            display: flex;
            justify-content: space-between;
            align-items: center;
            gap: 12px;
            margin-bottom: 14px;
            color: white;
        }

        .machine-title {
            display: flex;
            align-items: center;
            gap: 10px;
            font-weight: 950;
            letter-spacing: -.05em;
            font-size: clamp(22px, 4vw, 34px);
            text-shadow: 0 3px 18px rgba(0,0,0,.22);
        }

        .status-badge {
            padding: 9px 12px;
            border-radius: 999px;
            background: rgba(255,255,255,.22);
            border: 1px solid rgba(255,255,255,.3);
            font-size: 12px;
            font-weight: 900;
            text-transform: uppercase;
            letter-spacing: .06em;
            white-space: nowrap;
        }

        .reels {
            position: relative;
            z-index: 1;
            display: grid;
            grid-template-columns: repeat(3, 1fr);
            gap: 12px;
            padding: 14px;
            border-radius: 32px;
            background: rgba(34, 20, 7, .58);
            box-shadow: inset 0 12px 32px rgba(0,0,0,.32), 0 1px 0 rgba(255,255,255,.26);
        }

        .reel {
            display: grid;
            gap: 10px;
            padding: 10px;
            border-radius: 24px;
            background: linear-gradient(180deg, #fffdf2, #ffe8a7);
            box-shadow: inset 0 -10px 24px rgba(255,139,57,.18), inset 0 1px 0 white;
            overflow: hidden;
        }

        .cell {
            display: grid;
            place-items: center;
            aspect-ratio: 1 / .88;
            border-radius: 20px;
            background:
                radial-gradient(circle at 28% 20%, rgba(255,255,255,.94), transparent 32%),
                linear-gradient(145deg, rgba(255,255,255,.75), rgba(255,255,255,.15));
            border: 1px solid rgba(86,48,19,.09);
            font-size: clamp(42px, 7vw, 76px);
            text-shadow: 0 6px 16px rgba(86, 48, 19, .16);
            transition: transform .16s ease, filter .16s ease;
        }

        .spinning .cell {
            animation: blurSpin .16s linear infinite;
            filter: blur(.5px) saturate(1.3);
        }

        .cell.match {
            animation: pop .55s cubic-bezier(.2,1.55,.32,1) both;
            background: radial-gradient(circle, #fff 0%, #dcff91 48%, #68e879 100%);
        }

        @keyframes blurSpin {
            0% { transform: translateY(-3px) rotate(-2deg); }
            50% { transform: translateY(3px) rotate(2deg); }
            100% { transform: translateY(-3px) rotate(-2deg); }
        }

        @keyframes pop {
            0% { transform: scale(.86); }
            55% { transform: scale(1.18); }
            100% { transform: scale(1); }
        }

        .controls {
            position: relative;
            z-index: 1;
            display: grid;
            grid-template-columns: 1fr auto;
            gap: 12px;
            margin-top: 14px;
        }

        .spin-btn, .ghost-btn {
            border: 0;
            border-radius: 999px;
            font: inherit;
            font-weight: 950;
            cursor: pointer;
            transition: transform .18s ease, box-shadow .18s ease, opacity .18s ease;
        }

        .spin-btn {
            min-height: 64px;
            padding: 0 24px;
            color: #371602;
            background: linear-gradient(135deg, #fff47a, #ffbc31 45%, #ff7937);
            box-shadow: 0 16px 34px rgba(86, 48, 19, .34), inset 0 2px 0 rgba(255,255,255,.66);
            font-size: 20px;
            letter-spacing: -.02em;
        }

        .ghost-btn {
            min-width: 88px;
            padding: 0 18px;
            color: white;
            background: rgba(255,255,255,.18);
            border: 1px solid rgba(255,255,255,.28);
        }

        .spin-btn:hover, .ghost-btn:hover { transform: translateY(-2px); }
        .spin-btn:disabled { opacity: .62; cursor: wait; transform: none; }

        .message {
            position: relative;
            z-index: 1;
            min-height: 68px;
            margin-top: 14px;
            padding: 14px 16px;
            border-radius: 24px;
            color: #351902;
            background: rgba(255, 255, 255, .7);
            font-weight: 850;
            line-height: 1.35;
        }

        .paytable {
            display: grid;
            grid-template-columns: repeat(3, 1fr);
            gap: 10px;
            margin-top: 14px;
        }

        .pay {
            padding: 12px;
            border-radius: 18px;
            color: white;
            background: rgba(34,20,7,.18);
            border: 1px solid rgba(255,255,255,.18);
            font-weight: 900;
            text-align: center;
        }

        .sparkle {
            position: absolute;
            width: 180px;
            height: 180px;
            border-radius: 999px;
            background: radial-gradient(circle, rgba(255,255,255,.8), transparent 68%);
            filter: blur(4px);
            opacity: .32;
            pointer-events: none;
        }

        .sparkle.one { top: -40px; right: -44px; }
        .sparkle.two { bottom: 48px; left: -70px; }

        @media (max-width: 900px) {
            .hero { grid-template-columns: 1fr; padding-top: 22px; }
            h1 { max-width: 8ch; }
        }

        @media (max-width: 640px) {
            .navbar { align-items: flex-start; border-radius: 20px; }
            .nav-pill { display: none; }
            .score-grid, .paytable { grid-template-columns: 1fr; }
            .machine { padding: 14px; border-radius: 30px; }
            .reels { gap: 8px; padding: 10px; border-radius: 24px; }
            .reel { gap: 8px; padding: 8px; border-radius: 18px; }
            .cell { border-radius: 15px; font-size: clamp(30px, 13vw, 50px); }
            .controls { grid-template-columns: 1fr; }
            .ghost-btn { min-height: 46px; }
        }

        @media (prefers-reduced-motion: reduce) {
            *, *::before, *::after { animation: none !important; transition: none !important; }
        }
    </style>
</head>
<body>
    <main class="shell">
        <nav class="navbar" aria-label="Navegação do Frute Jogo">
            <div class="brand"><span class="brand-mark">🍒</span><span>KazaKora Frute Jogo</span></div>
            <div class="nav-pill">demo interativo · créditos fictícios</div>
        </nav>

        <section class="hero">
            <div class="copy-card">
                <span class="eyebrow">🍉 roleta de frutinhas</span>
                <h1>Gira, vibra e soma.</h1>
                <p class="lead">Um joguinho visual da KazaKora para brincar com três colunas de frutinhas, placar de créditos e resultado animado. A cada ciclo, a sorte mistura vitórias e erros para manter a roleta divertida.</p>
                <div class="disclaimer">Sem dinheiro real, sem depósito e sem aposta: os valores são créditos fictícios para diversão.</div>

                <div class="score-grid" aria-live="polite">
                    <div class="metric">
                        <small>Valor investido</small>
                        <strong id="invested">R$ 0</strong>
                    </div>
                    <div class="metric loss">
                        <small>Decrementado</small>
                        <strong id="lost">R$ 0</strong>
                    </div>
                    <div class="metric win">
                        <small>Acrescentado</small>
                        <strong id="won">R$ 0</strong>
                    </div>
                </div>
            </div>

            <div class="machine-wrap">
                <div class="machine" id="machine">
                    <span class="sparkle one"></span>
                    <span class="sparkle two"></span>

                    <div class="machine-top">
                        <div class="machine-title">🍇 Frute Slots</div>
                        <div class="status-badge" id="balance">Saldo: R$ 120</div>
                    </div>

                    <div class="reels" aria-label="Roleta de três colunas e três linhas">
                        <div class="reel" data-reel="0"></div>
                        <div class="reel" data-reel="1"></div>
                        <div class="reel" data-reel="2"></div>
                    </div>

                    <div class="controls">
                        <button class="spin-btn" id="spin" type="button">Girar por R$ 10</button>
                        <button class="ghost-btn" id="reset" type="button">Reset</button>
                    </div>

                    <div class="message" id="message">Aperta o botão e tenta alinhar as frutas no meio. 🍓🍓🍓 paga mais.</div>

                    <div class="paytable" aria-label="Tabela de prêmios fictícios">
                        <div class="pay">Linha simples<br>+R$ 22</div>
                        <div class="pay">Duas linhas<br>+R$ 45</div>
                        <div class="pay">Trinca especial<br>+R$ 90</div>
                    </div>
                </div>
            </div>
        </section>
    </main>

    <script>
        const fruits = ['🍒', '🍋', '🍇', '🍓', '🍊', '🍉', '🍍', '🍌'];
        const reels = Array.from(document.querySelectorAll('.reel'));
        const machine = document.getElementById('machine');
        const spinBtn = document.getElementById('spin');
        const resetBtn = document.getElementById('reset');
        const message = document.getElementById('message');
        const balanceEl = document.getElementById('balance');
        const investedEl = document.getElementById('invested');
        const lostEl = document.getElementById('lost');
        const wonEl = document.getElementById('won');

        const state = {
            balance: 120,
            invested: 0,
            lost: 0,
            won: 0,
            cost: 10,
            cycle: shuffle([true, true, true, true, true, true, true, false, false, false, false, false]),
            spinning: false,
        };

        function money(value) {
            return new Intl.NumberFormat('pt-BR', { style: 'currency', currency: 'BRL', maximumFractionDigits: 0 }).format(value);
        }

        function shuffle(items) {
            return [...items].sort(() => Math.random() - 0.5);
        }

        function randomFruit(except = null) {
            const pool = except ? fruits.filter(f => f !== except) : fruits;
            return pool[Math.floor(Math.random() * pool.length)];
        }

        function drawGrid(grid) {
            reels.forEach((reel, col) => {
                reel.innerHTML = '';
                for (let row = 0; row < 3; row++) {
                    const cell = document.createElement('div');
                    cell.className = 'cell';
                    cell.textContent = grid[row][col];
                    reel.appendChild(cell);
                }
            });
        }

        function randomGrid() {
            return Array.from({ length: 3 }, () => Array.from({ length: 3 }, () => randomFruit()));
        }

        function plannedGrid(shouldWin) {
            const grid = randomGrid();
            const row = Math.floor(Math.random() * 3);
            const fruit = randomFruit();

            if (shouldWin) {
                grid[row] = [fruit, fruit, fruit];
                if (Math.random() > .72) {
                    const secondRow = (row + 1 + Math.floor(Math.random() * 2)) % 3;
                    const secondFruit = randomFruit(fruit);
                    grid[secondRow] = [secondFruit, secondFruit, secondFruit];
                }
            } else {
                for (let r = 0; r < 3; r++) {
                    while (grid[r][0] === grid[r][1] && grid[r][1] === grid[r][2]) {
                        grid[r][2] = randomFruit(grid[r][0]);
                    }
                }
            }

            return grid;
        }

        function score(grid) {
            let lines = [];
            grid.forEach((row, index) => {
                if (row[0] === row[1] && row[1] === row[2]) {
                    lines.push({ index, fruit: row[0] });
                }
            });

            if (!lines.length) return { amount: 0, lines };
            const special = lines.some(line => ['🍓', '🍒', '🍇'].includes(line.fruit));
            const amount = lines.length >= 2 ? 45 : (special ? 90 : 22);
            return { amount, lines };
        }

        function highlight(lines) {
            document.querySelectorAll('.cell').forEach(cell => cell.classList.remove('match'));
            lines.forEach(({ index }) => {
                reels.forEach(reel => reel.children[index]?.classList.add('match'));
            });
        }

        function updateHud() {
            balanceEl.textContent = `Saldo: ${money(state.balance)}`;
            investedEl.textContent = money(state.invested);
            lostEl.textContent = money(state.lost);
            wonEl.textContent = money(state.won);
        }

        function nextOutcome() {
            if (!state.cycle.length) {
                state.cycle = shuffle([true, true, true, true, true, true, true, false, false, false, false, false]);
            }
            return state.cycle.pop();
        }

        async function spin() {
            if (state.spinning) return;
            if (state.balance < state.cost) {
                message.textContent = 'Saldo fictício acabou. Clique em Reset para brincar de novo.';
                return;
            }

            state.spinning = true;
            spinBtn.disabled = true;
            machine.classList.add('spinning');
            message.textContent = 'Girando as frutinhas... 🍍🍓🍋';
            state.balance -= state.cost;
            state.invested += state.cost;
            updateHud();

            const timer = setInterval(() => drawGrid(randomGrid()), 85);
            await new Promise(resolve => setTimeout(resolve, 850));
            clearInterval(timer);

            const grid = plannedGrid(nextOutcome());
            drawGrid(grid);
            const result = score(grid);
            highlight(result.lines);

            if (result.amount > 0) {
                state.balance += result.amount;
                state.won += result.amount;
                message.textContent = result.lines.length > 1
                    ? `Boa! Duas linhas bateram e somaram ${money(result.amount)} fictícios. 🎉`
                    : `Acertou uma linha de ${result.lines[0].fruit}${result.lines[0].fruit}${result.lines[0].fruit}: +${money(result.amount)} fictícios.`;
            } else {
                state.lost += state.cost;
                message.textContent = 'Quase! Essa rodada ficou no quase, mas a próxima pode virar. 🍋';
            }

            machine.classList.remove('spinning');
            state.spinning = false;
            spinBtn.disabled = false;
            updateHud();
        }

        function resetGame() {
            state.balance = 120;
            state.invested = 0;
            state.lost = 0;
            state.won = 0;
            state.cycle = shuffle([true, true, true, true, true, true, true, false, false, false, false, false]);
            drawGrid([['🍒', '🍋', '🍇'], ['🍓', '🍓', '🍓'], ['🍊', '🍉', '🍍']]);
            highlight([{ index: 1 }]);
            message.textContent = 'Jogo reiniciado. R$ 120 fictícios no saldo para brincar.';
            updateHud();
        }

        spinBtn.addEventListener('click', spin);
        resetBtn.addEventListener('click', resetGame);
        resetGame();
    </script>
</body>
</html>
