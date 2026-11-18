// Security regression test: the Vite dev server must not serve sensitive
// project files (database, logs, compiled config with APP_KEY, ...).
//
// Run with: npm run test:security
import { after, before, describe, test } from 'node:test';
import assert from 'node:assert/strict';
import { fileURLToPath } from 'node:url';
import { createServer, loadConfigFromFile } from 'vite';

const root = fileURLToPath(new URL('../..', import.meta.url));

let server;
let baseUrl;

before(async () => {
    const { config } = await loadConfigFromFile(
        { command: 'serve', mode: 'development' },
        `${root}/vite.config.js`,
        root,
    );

    // The Laravel plugin writes/removes public/hot, which would break a dev
    // server that is already running. It does not affect file serving rules.
    const plugins = (config.plugins ?? [])
        .flat(Infinity)
        .filter((plugin) => plugin && !String(plugin.name ?? '').startsWith('laravel'));

    server = await createServer({
        ...config,
        configFile: false,
        root,
        plugins,
        logLevel: 'silent',
        server: { ...config.server, host: '127.0.0.1', port: 0, strictPort: false, hmr: false, watch: null },
    });

    await server.listen();
    const { port } = server.httpServer.address();
    baseUrl = `http://127.0.0.1:${port}`;
});

after(async () => {
    await server?.close();
});

const fetchStatus = async (path) => (await fetch(`${baseUrl}${path}`)).status;

describe('Vite dev server file exposure', () => {
    const forbidden = [
        '/.env',
        '/.env?raw',
        '/database/database.sqlite?raw',
        '/database/database.sqlite?import&raw',
        '/storage/logs/laravel.log?raw',
        '/storage/inertia-devtools/_meta.json?raw',
        '/storage/framework/sessions/x?raw',
        '/bootstrap/cache/config.php?raw',
        '/bootstrap/cache/packages.php?raw',
        '/config/database.php?raw',
        '/vendor/autoload.php?raw',
        '/composer.json?raw',
        '/app/Models/EmailAccount.php?raw',
        '/routes/web.php?raw',
        '/docker/php/Dockerfile?raw',
        '/.phpunit.result.cache?raw',
        `/@fs${root}database/database.sqlite?raw`,
        '/@fs/etc/passwd?raw',
    ];

    for (const path of forbidden) {
        test(`does not serve ${path}`, async () => {
            const status = await fetchStatus(path);
            assert.ok(status === 403 || status === 404, `${path} returned HTTP ${status}`);
        });
    }

    test('still serves the frontend entrypoint', async () => {
        assert.equal(await fetchStatus('/resources/js/app.tsx'), 200);
    });

    test('still serves stylesheets', async () => {
        assert.equal(await fetchStatus('/resources/css/app.css'), 200);
    });
});
