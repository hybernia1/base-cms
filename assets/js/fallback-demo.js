(function () {
    const canvas = document.getElementById('gameCanvas');
    const ctx = canvas.getContext('2d');
    const TILE_SIZE = 48;
    const MAP_WIDTH = Math.floor(canvas.width / TILE_SIZE);
    const MAP_HEIGHT = Math.floor(canvas.height / TILE_SIZE);

    const seededRandom = (seed => () => {
        seed = (seed * 1664525 + 1013904223) % 4294967296;
        return seed / 4294967296;
    })(42);

    function createSprite(drawFn) {
        const offscreen = document.createElement('canvas');
        offscreen.width = TILE_SIZE;
        offscreen.height = TILE_SIZE;
        const context = offscreen.getContext('2d');
        drawFn(context);
        return offscreen;
    }

    function gradient(context, colors) {
        const grad = context.createLinearGradient(0, 0, TILE_SIZE, TILE_SIZE);
        colors.forEach(([pos, color]) => grad.addColorStop(pos, color));
        return grad;
    }

    const sprites = {
        wall: createSprite((c) => {
            c.fillStyle = gradient(c, [
                [0, '#556080'],
                [1, '#2a3048']
            ]);
            c.fillRect(0, 0, TILE_SIZE, TILE_SIZE);
            c.strokeStyle = 'rgba(255,255,255,0.08)';
            c.lineWidth = 2;
            for (let i = 6; i < TILE_SIZE; i += 12) {
                c.beginPath();
                c.moveTo(i, 0);
                c.lineTo(i - 6, TILE_SIZE);
                c.stroke();
            }
            c.strokeStyle = 'rgba(0,0,0,0.35)';
            c.beginPath();
            c.moveTo(0, TILE_SIZE);
            c.lineTo(TILE_SIZE, 0);
            c.stroke();
        }),
        floor: createSprite((c) => {
            c.fillStyle = gradient(c, [
                [0, '#1d2538'],
                [1, '#111726']
            ]);
            c.fillRect(0, 0, TILE_SIZE, TILE_SIZE);
            c.strokeStyle = 'rgba(255,255,255,0.05)';
            c.lineWidth = 1;
            for (let x = 8; x < TILE_SIZE; x += 8) {
                for (let y = 8; y < TILE_SIZE; y += 8) {
                    c.beginPath();
                    c.arc(x, y, 1, 0, Math.PI * 2);
                    c.stroke();
                }
            }
        }),
        player: createSprite((c) => {
            c.fillStyle = '#3fe0c5';
            c.beginPath();
            c.arc(TILE_SIZE / 2, TILE_SIZE / 2, TILE_SIZE / 2 - 6, 0, Math.PI * 2);
            c.fill();
            c.fillStyle = '#0d1b2a';
            c.beginPath();
            c.arc(TILE_SIZE / 2, TILE_SIZE / 2 - 4, 6, 0, Math.PI * 2);
            c.fill();
            c.strokeStyle = '#9cf2e7';
            c.lineWidth = 3;
            c.beginPath();
            c.arc(TILE_SIZE / 2, TILE_SIZE / 2, TILE_SIZE / 2 - 3, 0.1, Math.PI * 1.8);
            c.stroke();
        }),
        enemy: createSprite((c) => {
            c.fillStyle = '#ff6363';
            c.fillRect(8, 8, TILE_SIZE - 16, TILE_SIZE - 16);
            c.fillStyle = '#0b0f1a';
            c.fillRect(14, 14, TILE_SIZE / 3, TILE_SIZE / 3);
            c.fillRect(TILE_SIZE - 14 - TILE_SIZE / 3, 14, TILE_SIZE / 3, TILE_SIZE / 3);
            c.strokeStyle = '#ffc8c8';
            c.lineWidth = 3;
            c.beginPath();
            c.moveTo(10, TILE_SIZE - 10);
            c.lineTo(TILE_SIZE - 10, 10);
            c.stroke();
        }),
        collectible: createSprite((c) => {
            c.fillStyle = '#ffd166';
            c.beginPath();
            c.moveTo(TILE_SIZE / 2, 6);
            c.lineTo(TILE_SIZE - 6, TILE_SIZE / 2);
            c.lineTo(TILE_SIZE / 2, TILE_SIZE - 6);
            c.lineTo(6, TILE_SIZE / 2);
            c.closePath();
            c.fill();
            c.strokeStyle = '#fff4d8';
            c.lineWidth = 2;
            c.stroke();
            c.fillStyle = 'rgba(255, 255, 255, 0.35)';
            c.beginPath();
            c.arc(TILE_SIZE / 2, TILE_SIZE / 2, TILE_SIZE / 4, 0, Math.PI * 2);
            c.fill();
        })
    };

    function generateMap() {
        const map = [];
        for (let y = 0; y < MAP_HEIGHT; y++) {
            const row = [];
            for (let x = 0; x < MAP_WIDTH; x++) {
                const edge = x === 0 || y === 0 || x === MAP_WIDTH - 1 || y === MAP_HEIGHT - 1;
                if (edge) {
                    row.push('W');
                    continue;
                }
                const random = seededRandom();
                if (random > 0.94) {
                    row.push('W');
                } else if (random > 0.88) {
                    row.push('E');
                } else if (random > 0.80) {
                    row.push('C');
                } else {
                    row.push('.');
                }
            }
            map.push(row);
        }
        map[Math.floor(MAP_HEIGHT / 2)][Math.floor(MAP_WIDTH / 2)] = 'P';
        return map;
    }

    function drawMap(map) {
        ctx.imageSmoothingEnabled = false;
        for (let y = 0; y < map.length; y++) {
            for (let x = 0; x < map[y].length; x++) {
                const tile = map[y][x];
                ctx.drawImage(sprites.floor, x * TILE_SIZE, y * TILE_SIZE);
                if (tile === 'W') {
                    ctx.drawImage(sprites.wall, x * TILE_SIZE, y * TILE_SIZE);
                } else if (tile === 'E') {
                    ctx.drawImage(sprites.enemy, x * TILE_SIZE, y * TILE_SIZE);
                } else if (tile === 'C') {
                    ctx.drawImage(sprites.collectible, x * TILE_SIZE, y * TILE_SIZE);
                } else if (tile === 'P') {
                    ctx.drawImage(sprites.player, x * TILE_SIZE, y * TILE_SIZE);
                }
            }
        }
    }

    function label() {
        const text = 'Fallback režim – všechny sprity generovány na místě';
        ctx.fillStyle = 'rgba(0, 0, 0, 0.55)';
        ctx.fillRect(14, canvas.height - 48, ctx.measureText(text).width + 20, 36);
        ctx.fillStyle = '#e8edf7';
        ctx.font = '16px Roboto, sans-serif';
        ctx.fillText(text, 24, canvas.height - 24);
    }

    const map = generateMap();
    drawMap(map);
    label();
})();
