<?php
declare(strict_types=1);

require_once __DIR__ . '/api_test.php';

const RV_TOKEN = 'review-token-review-token-0123456789ab';

/** Runs $fn(ctx) against the dev server with the review token configured (or not, with null). */
function rv_with_server(?string $token, callable $fn): void
{
    putenv($token === null ? 'GUIDE_REVIEW_TOKEN' : 'GUIDE_REVIEW_TOKEN=' . $token);
    try {
        at_with_server($fn);
    } finally {
        putenv('GUIDE_REVIEW_TOKEN');
    }
}

function rv_bearer(array $ctx, string $method, string $target, string $body = '', ?string $token = RV_TOKEN): array
{
    return at_http($ctx, $method, $target, $body, $token === null ? [] : ['Authorization' => 'Bearer ' . $token]);
}

/** Saves the fixture guide as a draft (optionally edited) and publishes it; returns the version id. */
function rv_publish(array $ctx, string $slug = 'apto-1204', string $tagline = ''): string
{
    $g = at_guide();
    $g['slug'] = $slug;
    if ($tagline !== '') {
        $g['tagline']['es'] = $tagline;
    }
    eq(at_signed($ctx, 'PUT', "/api/guides/$slug/draft", at_json($g))['status'], 200);
    foreach (['a1.jpg', 'a2.jpg', 'a3.jpg'] as $name) {
        $u = at_upload($ctx, $slug, $name);
        ok(in_array($u['status'], [200, 409], true), 'upload ' . $u['raw']);
    }
    $p = at_signed($ctx, 'POST', "/api/guides/$slug/publish");
    eq($p['status'], 200, 'publish ' . $p['raw']);
    return $p['json']['version'];
}

function rv_body(string $version, string $summary = 'Todo bien en general.', array $findings = ['Revisa la foto 2.']): string
{
    return json_encode(['version' => $version, 'summary' => $summary, 'findings' => $findings], JSON_UNESCAPED_UNICODE);
}

/** Writes a published guide straight into the data dir (no HTTP), for queue ordering tests. */
function rv_fake_published(array $ctx, string $slug, string $version): void
{
    $dir = $ctx['data'] . '/' . $slug;
    mkdir($dir . '/versions', 0700, true);
    $g = at_guide();
    $g['slug'] = $slug;
    file_put_contents($dir . '/versions/' . $version . '.json', json_encode($g));
    file_put_contents($dir . '/published.json', json_encode(['version' => $version, 'guide' => $g]));
}

function rv_fake_review(array $ctx, string $slug, string $version, string $at): void
{
    $dir = $ctx['data'] . '/' . $slug . '/reviews';
    @mkdir($dir, 0700, true);
    file_put_contents($dir . '/' . $version . '.json', json_encode([
        'slug' => $slug, 'version' => $version, 'at' => $at, 'summary' => 'Resumen ' . $slug, 'findings' => [],
    ]));
}

test('review: queue lists a newly published guide and drops it after its review', function () {
    rv_with_server(RV_TOKEN, function ($ctx) {
        $v = rv_publish($ctx);
        $q = rv_bearer($ctx, 'GET', '/api/review/queue');
        eq($q['status'], 200, $q['raw']);
        eq($q['json']['ok'], true);
        eq(count($q['json']['items']), 1);
        $item = $q['json']['items'][0];
        eq($item['slug'], 'apto-1204');
        eq($item['version'], $v);
        eq($item['url'], 'https://delventto.cloud/apto-1204/');
        eq($item['guide'], at_guide());
        eq($item['previous'], null);
        eq($item['building'], ['id' => 'delventto', 'places' => []]);
        eq($q['headers']['cache-control'] ?? '', 'no-store');

        $r = rv_bearer($ctx, 'POST', '/api/review/apto-1204', rv_body($v));
        eq($r['status'], 200, $r['raw']);
        eq($r['json'], ['ok' => true]);
        eq(rv_bearer($ctx, 'GET', '/api/review/queue')['json']['items'], []);
        $file = json_decode((string) file_get_contents($ctx['data'] . "/apto-1204/reviews/$v.json"), true);
        eq($file['version'], $v);
        eq($file['findings'], ['Revisa la foto 2.']);
        ok(preg_match('/^\d{4}-\d\d-\d\dT\d\d:\d\d:\d\dZ$/', $file['at']) === 1, 'at is UTC ISO 8601');
    });
});

test('review: a new version comes back to the queue with the previous guide', function () {
    rv_with_server(RV_TOKEN, function ($ctx) {
        $v1 = rv_publish($ctx);
        eq(rv_bearer($ctx, 'POST', '/api/review/apto-1204', rv_body($v1))['status'], 200);
        $v2 = rv_publish($ctx, 'apto-1204', 'Versión dos del apartamento.');
        $items = rv_bearer($ctx, 'GET', '/api/review/queue')['json']['items'];
        eq(count($items), 1);
        eq($items[0]['version'], $v2);
        eq($items[0]['previous'], at_guide());
        eq($items[0]['guide']['tagline']['es'], 'Versión dos del apartamento.');
    });
});

test('review: queue is oldest first, at most 5, and skips unpublished guides', function () {
    rv_with_server(RV_TOKEN, function ($ctx) {
        foreach ([6, 3, 1, 5, 2, 4] as $n) {
            rv_fake_published($ctx, "apto-200$n", "2026111{$n}T100000Z");
        }
        eq(at_signed($ctx, 'PUT', '/api/guides/apto-2999/draft', at_json(['slug' => 'apto-2999'] + at_guide()))['status'], 200);
        $items = rv_bearer($ctx, 'GET', '/api/review/queue')['json']['items'];
        eq(array_column($items, 'slug'), ['apto-2001', 'apto-2002', 'apto-2003', 'apto-2004', 'apto-2005']);
    });
});

test('review: wrong or missing bearer is 401 and touches nothing', function () {
    rv_with_server(RV_TOKEN, function ($ctx) {
        $v = rv_publish($ctx);
        $before = at_dir_entries($ctx['data']);
        $tries = [
            ['GET', '/api/review/queue', ''],
            ['POST', '/api/review/apto-1204', rv_body($v)],
        ];
        foreach ($tries as [$m, $t, $b]) {
            foreach ([null, 'wrong-token-wrong-token-wrong-token-00', substr(RV_TOKEN, 0, -1)] as $token) {
                $r = rv_bearer($ctx, $m, $t, $b, $token);
                eq($r['status'], 401, "$m $t");
                eq($r['json']['ok'], false);
            }
            // The HMAC signature is not a credential for the bearer routes.
            eq(at_signed($ctx, $m, $t, $b)['status'], 401, "signed $m $t");
            // Neither is the token without the Bearer word.
            eq(at_http($ctx, $m, $t, $b, ['Authorization' => RV_TOKEN])['status'], 401);
        }
        eq(at_dir_entries($ctx['data']), $before);
        ok(!is_dir($ctx['data'] . '/apto-1204/reviews'), 'no review written');
    });
});

test('review: a review for an old or unknown version is 409', function () {
    rv_with_server(RV_TOKEN, function ($ctx) {
        $v1 = rv_publish($ctx);
        $v2 = rv_publish($ctx, 'apto-1204', 'Versión dos del apartamento.');
        $r = rv_bearer($ctx, 'POST', '/api/review/apto-1204', rv_body($v1));
        eq($r['status'], 409, $r['raw']);
        eq(rv_bearer($ctx, 'POST', '/api/review/apto-1204', rv_body('20200101T000000Z'))['status'], 409);
        eq(rv_bearer($ctx, 'POST', '/api/review/apto-7777', rv_body($v2))['status'], 409, 'unknown guide');
        ok(!is_dir($ctx['data'] . '/apto-1204/reviews'), 'nothing stored');
        eq(rv_bearer($ctx, 'POST', '/api/review/apto-1204', rv_body($v2))['status'], 200);
        eq(rv_bearer($ctx, 'POST', '/api/review/apto-1204', rv_body($v2))['status'], 409, 'second review of the same version');
    });
});

test('review: HTML, dashes and bad bodies are 400', function () {
    rv_with_server(RV_TOKEN, function ($ctx) {
        $v = rv_publish($ctx);
        $bad = [
            'html in summary' => rv_body($v, 'Mira <b>esto</b>'),
            'html in finding' => rv_body($v, 'Bien', ['<script>x</script>']),
            'em dash in summary' => rv_body($v, "Bien \u{2014} casi"),
            'en dash in finding' => rv_body($v, 'Bien', ["Foto 1 \u{2013} 2"]),
            'empty summary' => rv_body($v, '  '),
            'summary too long' => rv_body($v, str_repeat('a', 601)),
            'too many findings' => rv_body($v, 'Bien', array_fill(0, 21, 'Algo')),
            'finding too long' => rv_body($v, 'Bien', [str_repeat('b', 401)]),
            'finding not text' => json_encode(['version' => $v, 'summary' => 'Bien', 'findings' => [1]]),
            'findings not a list' => json_encode(['version' => $v, 'summary' => 'Bien', 'findings' => ['a' => 'x']]),
            'findings missing' => json_encode(['version' => $v, 'summary' => 'Bien']),
            'version missing' => json_encode(['summary' => 'Bien', 'findings' => []]),
            'version malformed' => rv_body('../x'),
            'not json' => 'hola',
            'a list' => '[1]',
        ];
        foreach ($bad as $name => $body) {
            $r = rv_bearer($ctx, 'POST', '/api/review/apto-1204', $body);
            eq($r['status'], 400, $name . ' ' . $r['raw']);
        }
        eq(rv_bearer($ctx, 'POST', '/api/review/NOPE', rv_body($v))['status'], 400, 'bad slug');
        ok(!is_dir($ctx['data'] . '/apto-1204/reviews'), 'nothing stored');
        // The limits themselves are fine, and an empty list of findings is a clean review.
        $edge = rv_body($v, str_repeat('a', 600), array_fill(0, 20, str_repeat('b', 400)));
        eq(rv_bearer($ctx, 'POST', '/api/review/apto-1204', $edge)['status'], 200);
    });
});

test('review: a clean review with no findings is accepted', function () {
    rv_with_server(RV_TOKEN, function ($ctx) {
        $v = rv_publish($ctx);
        eq(rv_bearer($ctx, 'POST', '/api/review/apto-1204', rv_body($v, 'Sin observaciones.', []))['status'], 200);
        $items = at_signed($ctx, 'GET', '/api/reviews')['json']['items'];
        eq($items[0]['findings'], []);
    });
});

test('review: wrong methods are 405 once authorized', function () {
    rv_with_server(RV_TOKEN, function ($ctx) {
        eq(rv_bearer($ctx, 'POST', '/api/review/queue', '{}')['status'], 405);
        $r = rv_bearer($ctx, 'GET', '/api/review/apto-1204');
        eq($r['status'], 405);
        eq($r['headers']['allow'] ?? '', 'POST');
        eq(rv_bearer($ctx, 'GET', '/api/review/apto-1204/extra')['status'], 404);
    });
});

test('review: /api/reviews needs the HMAC signature and returns only newer reviews, oldest first', function () {
    rv_with_server(RV_TOKEN, function ($ctx) {
        $v1 = rv_publish($ctx, 'apto-1204');
        $v2 = rv_publish($ctx, 'apto-1205');
        eq(rv_bearer($ctx, 'POST', '/api/review/apto-1204', rv_body($v1, 'Resumen uno'))['status'], 200);
        // Make the first review old, then add a fresh one.
        $file = $ctx['data'] . "/apto-1204/reviews/$v1.json";
        $r1 = json_decode((string) file_get_contents($file), true);
        $r1['at'] = '2026-01-01T00:00:00Z';
        file_put_contents($file, json_encode($r1));
        eq(rv_bearer($ctx, 'POST', '/api/review/apto-1205', rv_body($v2, 'Resumen dos'))['status'], 200);

        // A bearer alone is not enough, nor a missing or stale signature.
        eq(rv_bearer($ctx, 'GET', '/api/reviews')['status'], 401);
        eq(at_http($ctx, 'GET', '/api/reviews')['status'], 401);
        eq(at_signed($ctx, 'GET', '/api/reviews', '', ['ts' => time() - 301])['status'], 401);

        $all = at_signed($ctx, 'GET', '/api/reviews');
        eq($all['status'], 200, $all['raw']);
        eq(array_column($all['json']['items'], 'slug'), ['apto-1204', 'apto-1205']);
        eq(array_keys($all['json']['items'][0]), ['slug', 'version', 'at', 'summary', 'findings', 'url']);
        eq($all['json']['items'][0]['at'], '2026-01-01T00:00:00Z');
        eq($all['json']['items'][1]['url'], 'https://delventto.cloud/apto-1205/');

        $new = at_signed($ctx, 'GET', '/api/reviews?since=' . rawurlencode('2026-06-01T00:00:00Z'));
        eq(array_column($new['json']['items'], 'slug'), ['apto-1205']);
        eq($new['json']['items'][0]['summary'], 'Resumen dos');
        $none = at_signed($ctx, 'GET', '/api/reviews?since=' . rawurlencode('2099-01-01T00:00:00+00:00'));
        eq($none['json']['items'], []);
        eq(at_signed($ctx, 'GET', '/api/reviews?since=ayer')['status'], 400);
        eq(at_signed($ctx, 'POST', '/api/reviews', '{}')['status'], 405);
    });
});

test('review: /api/reviews returns at most 20, oldest first', function () {
    rv_with_server(RV_TOKEN, function ($ctx) {
        for ($i = 1; $i <= 22; $i++) {
            $slug = sprintf('apto-30%02d', $i);
            rv_fake_review($ctx, $slug, '20261101T100000Z', sprintf('2026-11-02T10:00:%02dZ', $i));
        }
        $r = at_signed($ctx, 'GET', '/api/reviews');
        eq(count($r['json']['items']), 20);
        eq($r['json']['items'][0]['slug'], 'apto-3001');
        eq($r['json']['items'][19]['slug'], 'apto-3020');
        $rest = at_signed($ctx, 'GET', '/api/reviews?since=' . rawurlencode('2026-11-02T10:00:20Z'));
        eq(array_column($rest['json']['items'], 'slug'), ['apto-3021', 'apto-3022']);
    });
});

test('review: with no token configured every review route is 404, even signed or with a bearer', function () {
    rv_with_server(null, function ($ctx) {
        $routes = [['GET', '/api/review/queue'], ['POST', '/api/review/apto-1204'], ['GET', '/api/reviews']];
        foreach ($routes as [$m, $t]) {
            eq(at_http($ctx, $m, $t)['status'], 404, "plain $m $t");
            eq(at_signed($ctx, $m, $t, $m === 'POST' ? '{}' : '')['status'], 404, "signed $m $t");
            eq(rv_bearer($ctx, $m, $t, $m === 'POST' ? '{}' : '')['status'], 404, "bearer $m $t");
        }
        eq(at_dir_entries($ctx['data']), []);
    });
    // Too short counts as unset.
    rv_with_server('too-short', function ($ctx) {
        eq(rv_bearer($ctx, 'GET', '/api/review/queue', '', 'too-short')['status'], 404);
        eq(at_signed($ctx, 'GET', '/api/reviews')['status'], 404);
    });
});

test('review: the guide routes do not depend on the review token', function () {
    rv_with_server(null, function ($ctx) {
        eq(at_signed($ctx, 'GET', '/api/guides/apto-7777')['status'], 200);
    });
});

test('config: guide_review_token trims, needs 32 characters and is null when unset', function () {
    try {
        putenv('GUIDE_REVIEW_TOKEN=' . RV_TOKEN . "\n");
        eq(guide_review_token(), RV_TOKEN);
        putenv('GUIDE_REVIEW_TOKEN=' . str_repeat('x', 31));
        eq(guide_review_token(), null);
        putenv('GUIDE_REVIEW_TOKEN=' . str_repeat('x', 32));
        eq(guide_review_token(), str_repeat('x', 32));
    } finally {
        putenv('GUIDE_REVIEW_TOKEN');
    }
});

test('workflow: deploy writes review_token from the secret without echoing it', function () {
    $t = (string) file_get_contents(__DIR__ . '/../.github/workflows/deploy.yml');
    ok(strpos($t, 'GUIDE_REVIEW_TOKEN: ${{ secrets.GUIDE_REVIEW_TOKEN }}') !== false, 'secret passed through env');
    ok(strpos($t, 'envs: GUIDE_API_SECRET,GUIDE_REVIEW_TOKEN') !== false, 'envs lists the token');
    ok(strpos($t, "'review_token' =>") !== false, 'config gets a review_token key');
    ok(preg_match('/\$\{\{[^}]*GUIDE_REVIEW_TOKEN[^}]*\}\}/', preg_replace('/^\s*GUIDE_REVIEW_TOKEN: \$\{\{ secrets\.GUIDE_REVIEW_TOKEN \}\}\s*$/m', '', $t)) !== 1, 'secret only in env, never in script text');
    ok(preg_match('/echo[^\n]*\$GUIDE_REVIEW_TOKEN/', $t) !== 1 && strpos($t, 'set -x') === false, 'no echo or tracing of the token');
});

test('workflow: docs folder is excluded from the deploy and removed on the host', function () {
    $t = (string) file_get_contents(__DIR__ . '/../.github/workflows/deploy.yml');
    ok(strpos($t, '!docs') !== false, 'source excludes docs');
    ok(strpos($t, '/home/rentals77/delventto.cloud/docs') !== false, 'harden step removes docs');
});

test('temas: the no-animation card button reads "Ver sin animación"', function () {
    $t = (string) file_get_contents(__DIR__ . '/../temas/index.php');
    ok(strpos($t, 'Ver sin animación') !== false && strpos($t, 'Ver ninguno') === false);
});
