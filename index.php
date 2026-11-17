<?php
declare(strict_types=1);

// ── settings ────────────────────────────────────────────
// LOCAL TESTING: DATA_DIR = __DIR__ . '/data'      EC2 SERVER: '/var/lib/rsvp_data'
const DATA_DIR    = __DIR__ . '/data';
const GUESTS_FILE = DATA_DIR . '/guests.txt';
const RESP_FILE   = DATA_DIR . '/responses.txt';
const DEADLINE    = '2026-12-13 23:59:59';
const MAX_RESULTS = 5;

date_default_timezone_set('Asia/Manila');
session_set_cookie_params(['httponly' => true, 'samesite' => 'Lax', 'secure' => !empty($_SERVER['HTTPS'])]);
session_start();
$_SESSION['csrf'] = $_SESSION['csrf'] ?? bin2hex(random_bytes(16));

// ── helpers ─────────────────────────────────────────────
function norm(string $s): string {
    $s = mb_strtolower($s, 'UTF-8');
    $s = preg_replace('/[^a-z0-9 ]/u', '', $s) ?? '';
    return trim(preg_replace('/\s+/', ' ', $s) ?? '');
}

function json_out(array $data, int $code = 200) {
    http_response_code($code);
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    header('X-Content-Type-Options: nosniff');
    echo json_encode($data, JSON_UNESCAPED_UNICODE);
    exit;
}

// guests.txt: one "party,name" per line  →  [party => [name, name, ...]]
function read_guests(): array {
    $parties = [];
    if (!is_file(GUESTS_FILE)) return $parties;
    $fh = fopen(GUESTS_FILE, 'r');
    if (!$fh) return $parties;
    flock($fh, LOCK_SH);
    while (($r = fgetcsv($fh, 0, ',', '"', '')) !== false) {
        if ($r === [null] || count($r) < 2) continue;
        $party = trim((string)preg_replace('/^\xEF\xBB\xBF/', '', (string)$r[0]));
        $name  = trim((string)$r[1]);
        if ($name === '' || strtolower($party) === 'party') continue;
        if (!preg_match('/^[A-Za-z0-9_-]{1,20}$/', $party)) continue;
        $parties[$party][] = $name;
    }
    flock($fh, LOCK_UN);
    fclose($fh);
    return $parties;
}

// responses.txt: time,party,name,answer,message   (the latest line for each person wins)
function read_status(): array {
    $map = [];
    if (!is_file(RESP_FILE)) return $map;
    $fh = fopen(RESP_FILE, 'r');
    if (!$fh) return $map;
    flock($fh, LOCK_SH);
    while (($r = fgetcsv($fh, 0, ',', '"', '')) !== false) {
        if ($r === [null] || count($r) < 4) continue;
        $map[$r[1] . '|' . $r[2]] = $r[3];
    }
    flock($fh, LOCK_UN);
    fclose($fh);
    return $map;
}

$closed = date('Y-m-d H:i:s') > DEADLINE;

// ── 1. live search:  index.php?suggest=mar ──────────────
if (isset($_GET['suggest'])) {
    $q = norm((string)$_GET['suggest']);
    $words = array_slice(array_values(array_filter(explode(' ', $q), fn($w) => mb_strlen($w) >= 2)), 0, 4);
    if (mb_strlen($q) < 3 || !$words) json_out(['parties' => []]);

    $out = [];
    foreach (read_guests() as $p => $names) {
        foreach ($names as $name) {
            $n = norm($name);
            $hit = true;
            foreach ($words as $w) { if (!str_contains($n, $w)) { $hit = false; break; } }
            if ($hit) { $out[] = ['match' => $name, 'names' => $names]; break; }
        }
        if (count($out) >= MAX_RESULTS) break;
    }
    json_out(['parties' => $out]);
}

// ── 2. open an invitation:  index.php?open=Maria Santos ─
if (isset($_GET['open'])) {
    $wanted = norm((string)$_GET['open']);
    $status = read_status();
    foreach (read_guests() as $p => $names) {
        foreach ($names as $name) {
            if (norm($name) === $wanted) {
                $_SESSION['party'] = (string)$p;        // remembered on the server: only THIS invitation can be saved
                $members = [];
                foreach ($names as $n) $members[] = ['name' => $n, 'status' => $status[$p . '|' . $n] ?? ''];
                json_out(['ok' => true, 'closed' => $closed, 'members' => $members]);
            }
        }
    }
    json_out(['ok' => false], 404);
}

// ── 3. save the answers:  POST index.php?save ───────────
if (isset($_GET['save'])) {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') json_out(['ok' => false], 405);
    if (!hash_equals($_SESSION['csrf'], (string)($_SERVER['HTTP_X_CSRF_TOKEN'] ?? ''))) json_out(['ok' => false, 'error' => 'session'], 403);
    if ($closed) json_out(['ok' => false, 'error' => 'closed'], 403);

    $all   = read_guests();
    $party = $_SESSION['party'] ?? null;
    if ($party === null || !isset($all[$party])) json_out(['ok' => false, 'error' => 'no_invitation'], 400);

    $d = json_decode(file_get_contents('php://input', false, null, 0, 20000) ?: '', true);
    $answers = is_array($d['answers'] ?? null) ? array_values($d['answers']) : [];
    $names   = $all[$party];
    if (count($answers) !== count($names)) json_out(['ok' => false, 'error' => 'incomplete'], 400);
    foreach ($answers as $a) { if ($a !== 'Yes' && $a !== 'No') json_out(['ok' => false, 'error' => 'incomplete'], 400); }

    $message = mb_substr(trim((string)($d['message'] ?? '')), 0, 500);
    $message = str_replace(["\r", "\n"], ' ', $message);
    if (preg_match('/^[=+\-@\t]/', $message)) $message = "'" . $message;      // blocks spreadsheet formulas

    $fh = fopen(RESP_FILE, 'a');
    if (!$fh) { error_log('index.php: cannot open responses file'); json_out(['ok' => false, 'error' => 'server'], 500); }
    flock($fh, LOCK_EX);
    $now = date('Y-m-d H:i:s');
    foreach ($names as $i => $name) {
        fputcsv($fh, [$now, $party, $name, $answers[$i], $i === 0 ? $message : ''], ',', '"', '');
    }
    fflush($fh);
    flock($fh, LOCK_UN);
    fclose($fh);
    json_out(['ok' => true]);
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8"/>
<meta name="viewport" content="width=device-width, initial-scale=1.0"/>
<title>Jheroll & Jennie · February 6, 2027</title>
<meta name="csrf-token" content="<?= htmlspecialchars($_SESSION['csrf'], ENT_QUOTES) ?>">  
<link href="https://fonts.googleapis.com/css2?family=Playfair+Display:ital,wght@0,400;0,700;0,900;1,400;1,700;1,900&family=Cormorant+Upright:wght@300;400;500;600;700&family=Cormorant+Garamond:ital,wght@0,300;0,400;0,500;1,300;1,400;1,500&family=Josefin+Sans:wght@200;300;400&family=Great+Vibes&family=Pinyon+Script&display=swap" rel="stylesheet"/>
<style>
/* ── RESET & BASE ── */
*{margin:0;padding:0;box-sizing:border-box;}
html{scroll-behavior:smooth;}
body{background:#FDF6EE;color:#3D0C11;font-family:'Cormorant Garamond',serif;}

/* ── PAGES ── */
.page{display:none;}
.page.active{display:block;}

/* ── NAV ── */
.nav{position:fixed;top:0;left:0;right:0;z-index:100;background:rgba(253,246,238,0.93);backdrop-filter:blur(8px);border-bottom:0.5px solid #E8B4BB;padding:0 2rem;height:60px;display:grid;grid-template-columns:1fr auto 1fr;align-items:center;}
.nav-brand{font-family:'Pinyon Script',cursive;font-size:1.6rem;color:#7D1D30;letter-spacing:0.02em;cursor:pointer;}
.nav-links{display:flex;gap:2rem;list-style:none;}
.nav-links a{font-family:'Josefin Sans',sans-serif;font-size:0.62rem;letter-spacing:0.2em;text-transform:uppercase;color:#5C1523;text-decoration:none;transition:color 0.2s;}
.nav-links a:hover{color:#C9A84C;}

/* ── HERO ── */
.hero{min-height:100vh;display:flex;flex-direction:column;align-items:center;justify-content:center;text-align:center;padding:80px 2rem 4rem;background:linear-gradient(160deg,#FDF6EE 0%,#F8EEE8 50%,#FDF6EE 100%);position:relative;overflow:hidden;}
.hero::before{content:'';position:absolute;inset:0;background-image:radial-gradient(ellipse at 20% 50%,#F5E6E888 0%,transparent 60%),radial-gradient(ellipse at 80% 20%,#E8D5A344 0%,transparent 50%);}
.hero-inner{position:relative;z-index:1;}
.hero-ornament{font-size:0.7rem;font-family:'Josefin Sans',sans-serif;letter-spacing:0.35em;text-transform:uppercase;color:#C9A84C;margin-bottom:1.5rem;}
.hero-names{font-family:'Pinyon Script',cursive;font-size:clamp(5rem,13vw,10rem);font-weight:400;line-height:1.0;color:#7D1D30;font-style:normal;letter-spacing:0.02em;}
.hero-amp{font-family:'Pinyon Script',cursive;color:#C9A84C;font-size:clamp(4.5rem,11vw,8.5rem);display:block;line-height:0.85;letter-spacing:0;}
.hero-date{font-family:'Josefin Sans',sans-serif;font-size:0.75rem;letter-spacing:0.3em;text-transform:uppercase;color:#9B2335;margin-top:1.5rem;}
.hero-divider{width:120px;height:1px;background:linear-gradient(90deg,transparent,#C9A84C,transparent);margin:1.5rem auto;}
.hero-tagline{font-family:'Cormorant Garamond',serif;font-style:italic;font-size:1.3rem;color:#5C1523;font-weight:300;}
.parol{position:absolute;pointer-events:none;}

/* ── SECTIONS ── */
section{padding:5rem 2rem;}
.inner{max-width:900px;margin:0 auto;}
.sec-label{font-family:'Josefin Sans',sans-serif;font-size:0.62rem;letter-spacing:0.35em;text-transform:uppercase;color:#C9A84C;text-align:center;margin-bottom:0.75rem;}
.sec-title{font-family:'Cormorant Garamond',serif;font-style:italic;font-size:clamp(2rem,5vw,3.2rem);font-weight:400;color:#7D1D30;text-align:center;margin-bottom:0.5rem;}
.sec-div{width:80px;height:1px;background:linear-gradient(90deg,transparent,#C9A84C,transparent);margin:1.2rem auto 2.5rem;}

/* ── TIMELINE ── */
.timeline{display:flex;flex-direction:column;gap:2.5rem;position:relative;padding-left:2rem;}
.timeline::before{content:'';position:absolute;left:0.5rem;top:0.5rem;bottom:0.5rem;width:1px;background:linear-gradient(180deg,transparent,#E8B4BB,#E8B4BB,transparent);}
.t-item{position:relative;padding-left:2rem;}
.t-dot{position:absolute;left:-1.65rem;top:0.35rem;width:10px;height:10px;border-radius:50%;background:#C9A84C;border:2px solid #FDF6EE;box-shadow:0 0 0 1px #C9A84C;}
.t-year{font-family:'Josefin Sans',sans-serif;font-size:0.6rem;letter-spacing:0.25em;text-transform:uppercase;color:#C9A84C;margin-bottom:0.3rem;}
.t-text{font-family:'Cormorant Garamond',serif;font-size:1.15rem;line-height:1.8;color:#5C1523;}
.t-text strong{color:#7D1D30;font-weight:500;}

/* ── VENUE WITH PHOTO ── */
.venue-hero{position:relative;border-radius:4px;overflow:hidden;margin-bottom:2rem;}
.venue-hero-img{width:100%;height:480px;object-fit:cover;display:block;filter:brightness(0.62);}
.venue-hero-overlay{position:absolute;inset:0;background:linear-gradient(to top,rgba(30,5,8,0.85) 0%,rgba(30,5,8,0.3) 50%,rgba(30,5,8,0.15) 100%);}
.venue-hero-content{position:absolute;bottom:0;left:0;right:0;padding:2.5rem 3rem;text-align:center;}
.venue-hero-content .venue-name{font-family:'Pinyon Script',cursive;font-size:2.8rem;color:#E8D5A3;margin-bottom:0.4rem;letter-spacing:0.02em;}
.venue-hero-content .venue-addr{font-family:'Josefin Sans',sans-serif;font-size:0.65rem;letter-spacing:0.18em;color:#E8B4BB;margin-bottom:1.5rem;}
.venue-hero-content .venue-deets{display:flex;justify-content:center;gap:3rem;flex-wrap:wrap;margin-bottom:1.8rem;}
.venue-hero-content .v-label{font-family:'Josefin Sans',sans-serif;font-size:0.55rem;letter-spacing:0.2em;text-transform:uppercase;color:#E8B4BB;}
.venue-hero-content .v-val{font-family:'Cormorant Garamond',serif;font-size:1.15rem;color:#E8D5A3;}
.venue-hero-content .map-link{display:inline-block;font-family:'Josefin Sans',sans-serif;font-size:0.62rem;letter-spacing:0.2em;text-transform:uppercase;color:#E8D5A3;border:0.5px solid rgba(201,168,76,0.6);padding:0.65rem 1.8rem;text-decoration:none;transition:all 0.25s;border-radius:1px;}
.venue-hero-content .map-link:hover{background:rgba(201,168,76,0.2);border-color:#C9A84C;}
.venue-divider{display:flex;align-items:center;gap:1rem;margin:0.5rem auto 0;max-width:300px;justify-content:center;}
.venue-divider-line{flex:1;height:0.5px;background:rgba(201,168,76,0.4);}
.venue-divider-diamond{color:#C9A84C;font-size:0.6rem;}
.venue-card{background:#7D1D30;color:#FDF6EE;border-radius:2px;padding:3rem;text-align:center;position:relative;overflow:hidden;}
.venue-card::before{content:'';position:absolute;inset:8px;border:0.5px solid rgba(232,180,187,0.27);border-radius:2px;pointer-events:none;}
.venue-name{font-family:'Cormorant Garamond',serif;font-size:2rem;color:#E8D5A3;margin-bottom:0.5rem;}
.venue-addr{font-family:'Josefin Sans',sans-serif;font-size:0.68rem;letter-spacing:0.12em;color:#E8B4BB;margin-bottom:1.5rem;}
.venue-deets{display:flex;justify-content:center;gap:3rem;flex-wrap:wrap;margin-bottom:2rem;}
.v-label{font-family:'Josefin Sans',sans-serif;font-size:0.6rem;letter-spacing:0.2em;text-transform:uppercase;color:#E8B4BB;}
.v-val{font-family:'Cormorant Garamond',serif;font-size:1.2rem;color:#E8D5A3;}
.map-link{display:inline-block;font-family:'Josefin Sans',sans-serif;font-size:0.65rem;letter-spacing:0.2em;text-transform:uppercase;color:#E8D5A3;border:0.5px solid #C9A84C;padding:0.7rem 1.8rem;text-decoration:none;transition:all 0.25s;}
.map-link:hover{background:#C9A84C;color:#3D0C11;}
.venue-note{text-align:center;margin-top:1.5rem;font-family:'Cormorant Garamond',serif;color:#9B2335;font-size:1.05rem;}

/* ── OUTFITS ── */
.outfit-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(145px,1fr));gap:1.25rem;}
.outfit-card{border:0.5px solid #E8B4BB;border-radius:2px;padding:1.25rem;text-align:center;transition:box-shadow 0.2s;background:#FDF6EE;}
.outfit-card:hover{box-shadow:0 4px 24px rgba(232,180,187,0.53);}
.outfit-card.featured{border-color:#C9A84C;background:#F5E6E8;}
.swatch{width:52px;height:52px;border-radius:50%;margin:0 auto 0.9rem;border:2px solid #E8D5A3;}
.o-role{font-family:'Josefin Sans',sans-serif;font-size:0.6rem;letter-spacing:0.25em;text-transform:uppercase;color:#C9A84C;margin-bottom:0.3rem;}
.o-color{font-family:'Cormorant Garamond',serif;font-size:1.1rem;color:#7D1D30;}
.o-desc{font-family:'Josefin Sans',sans-serif;font-size:0.62rem;letter-spacing:0.05em;color:#9B2335;margin-top:0.3rem;}
.outfit-note{text-align:center;margin-top:2rem;font-family:'Josefin Sans',sans-serif;font-size:0.65rem;letter-spacing:0.1em;color:#9B2335;}

/* ── SPOTIFY ── */
.spotify-card{background:#F5E6E8;border-radius:2px;padding:2.5rem;display:flex;align-items:center;gap:2rem;flex-wrap:wrap;justify-content:center;text-align:center;}
.sp-icon{width:64px;height:64px;background:#1DB954;border-radius:50%;display:flex;align-items:center;justify-content:center;flex-shrink:0;}
.sp-title{font-family:'Cormorant Garamond',serif;font-size:1.6rem;color:#7D1D30;margin-bottom:0.3rem;}
.sp-sub{font-family:'Josefin Sans',sans-serif;font-size:0.65rem;letter-spacing:0.15em;color:#9B2335;margin-bottom:1rem;}
.sp-btn{display:inline-flex;align-items:center;gap:0.5rem;background:#1DB954;color:#fff;font-family:'Josefin Sans',sans-serif;font-size:0.65rem;letter-spacing:0.2em;text-transform:uppercase;padding:0.75rem 2rem;border-radius:50px;text-decoration:none;transition:opacity 0.2s;}
.sp-btn:hover{opacity:0.85;}

/* ── SPONSORS ── */
.sponsors-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(220px,1fr));gap:1.5rem;}
.spon-card{border:0.5px solid #E8B4BB;border-radius:2px;padding:1.5rem;background:#FDF6EE;}
.spon-cat{font-family:'Josefin Sans',sans-serif;font-size:0.55rem;letter-spacing:0.3em;text-transform:uppercase;color:#C9A84C;margin-bottom:0.4rem;}
.spon-name{font-family:'Cormorant Garamond',serif;font-size:1.2rem;color:#7D1D30;font-weight:500;}
.spon-note{font-family:'Josefin Sans',sans-serif;font-size:0.65rem;color:#9B2335;margin-top:0.25rem;}

/* ── ENTOURAGE ── */
.ent-group{margin-bottom:2.8rem;}
.ent-gtitle{font-family:'Josefin Sans',sans-serif;font-size:0.6rem;letter-spacing:0.3em;text-transform:uppercase;color:#C9A84C;text-align:center;margin-bottom:1.3rem;}
.ent-names{display:flex;flex-wrap:wrap;justify-content:center;align-items:center;gap:0.5rem 1.2rem;margin-bottom:0.8rem;}
.ent-names:last-child{margin-bottom:0;}
.ent-name{font-family:'Cormorant Garamond',serif;font-size:1.1rem;line-height:1.5;color:#5C1523;}
.ent-sub{font-family:'Cormorant Garamond',serif;font-style:italic;font-size:0.95rem;color:#9A6B75;text-align:center;margin:1rem 0 0.4rem;}

/* ── FOOTER ── */
footer{background:#3D0C11;color:#E8B4BB;text-align:center;padding:3rem 2rem;}
.footer-names{font-family:'Pinyon Script',cursive;font-size:2.8rem;color:#E8D5A3;margin-bottom:0.5rem;letter-spacing:0.02em;}
.footer-date{font-family:'Josefin Sans',sans-serif;font-size:0.6rem;letter-spacing:0.3em;text-transform:uppercase;color:rgba(232,180,187,0.4);}
.footer-love{margin-top:1.5rem;font-family:'Josefin Sans',sans-serif;font-size:0.55rem;letter-spacing:0.15em;color:rgba(232,180,187,0.27);}

/* ── RSVP PAGE ── */
.rsvp-page{min-height:100vh;display:flex;flex-direction:column;align-items:center;justify-content:center;padding:5rem 2rem 3rem;background:#FDF6EE;}
.rsvp-top{text-align:center;margin-bottom:2.5rem;}
.rsvp-names{font-family:'Pinyon Script',cursive;font-size:3.5rem;font-weight:400;color:#7D1D30;line-height:1;letter-spacing:0.02em;}
.rsvp-amp{color:#C9A84C;}
.rsvp-subtitle{font-family:'Josefin Sans',sans-serif;font-size:0.65rem;letter-spacing:0.2em;text-transform:uppercase;color:#9B2335;}
.rsvp-card{background:#fff;border:0.5px solid #E8B4BB;border-radius:2px;padding:2.5rem;width:100%;max-width:520px;}
.f-label{display:block;font-family:'Josefin Sans',sans-serif;font-size:0.58rem;letter-spacing:0.2em;text-transform:uppercase;color:#9B2335;margin-bottom:0.5rem;}
.f-input{width:100%;border:0.5px solid #E8B4BB;border-radius:1px;padding:0.75rem 1rem;font-family:'Cormorant Garamond',serif;font-size:1rem;color:#3D0C11;background:transparent;outline:none;transition:border-color 0.2s;}
.f-input:focus{border-color:#C9A84C;}
.f-field{margin-bottom:1.5rem;}
.f-radios{display:flex;gap:1.5rem;flex-wrap:wrap;}
.f-radio{display:flex;align-items:center;gap:0.5rem;cursor:pointer;}
.f-radio input{accent-color:#7D1D30;width:16px;height:16px;}
.f-radio span{font-family:'Cormorant Garamond',serif;font-size:1.05rem;color:#5C1523;}
.f-select{width:100%;border:0.5px solid #E8B4BB;border-radius:1px;padding:0.75rem 1rem;font-family:'Josefin Sans',sans-serif;font-size:0.75rem;color:#3D0C11;background:#FDF6EE;outline:none;cursor:pointer;}
.f-btn{width:100%;background:#7D1D30;color:#FDF6EE;border:none;padding:1rem;font-family:'Josefin Sans',sans-serif;font-size:0.65rem;letter-spacing:0.3em;text-transform:uppercase;cursor:pointer;transition:background 0.2s;margin-top:0.5rem;}
.f-btn:hover{background:#5C1523;}
.f-error{font-family:'Josefin Sans',sans-serif;font-size:0.6rem;color:#c0392b;margin-top:0.4rem;letter-spacing:0.05em;}
.rsvp-success{text-align:center;padding:1rem 0;}
.s-icon{font-size:3rem;margin-bottom:1rem;}
.s-title{font-family:'Cormorant Garamond',serif;font-size:2.2rem;color:#7D1D30;margin-bottom:0.75rem;}
.s-text{font-family:'Josefin Sans',sans-serif;font-size:0.65rem;letter-spacing:0.1em;line-height:2;color:#9B2335;}
.guests-field{display:none;}
.guests-field.show{display:block;}

/* ── DASHBOARD ── */
.dash-page{min-height:100vh;background:#3D0C11;color:#FDF6EE;font-family:'Josefin Sans',sans-serif;}
.login-wrap{min-height:100vh;display:flex;flex-direction:column;align-items:center;justify-content:center;padding:2rem;}
.login-title{font-family:'Cormorant Garamond',serif;font-size:2rem;color:#E8D5A3;margin-bottom:0.5rem;text-align:center;}
.login-sub{font-size:0.6rem;letter-spacing:0.25em;text-transform:uppercase;color:#E8B4BB;margin-bottom:2rem;text-align:center;}
.login-box{background:#5C1523;border:0.5px solid #9B2335;border-radius:2px;padding:2.5rem;width:100%;max-width:360px;}
.d-label{display:block;font-size:0.55rem;letter-spacing:0.2em;text-transform:uppercase;color:#E8D5A3;margin-bottom:0.5rem;}
.d-input{width:100%;background:#3D0C11;border:0.5px solid #9B2335;border-radius:1px;padding:0.75rem 1rem;font-family:'Josefin Sans',sans-serif;font-size:0.85rem;color:#FDF6EE;outline:none;transition:border-color 0.2s;}
.d-input:focus{border-color:#C9A84C;}
.login-btn{width:100%;background:#7D1D30;color:#FDF6EE;border:none;padding:0.9rem;font-family:'Josefin Sans',sans-serif;font-size:0.6rem;letter-spacing:0.25em;text-transform:uppercase;cursor:pointer;margin-top:1.25rem;transition:background 0.2s;}
.login-btn:hover{background:#9B2335;}
.login-err{font-size:0.6rem;color:#e07070;margin-top:0.75rem;text-align:center;letter-spacing:0.05em;display:none;}
.dash-inner{padding:2rem;display:none;}
.dash-header{display:flex;align-items:center;justify-content:space-between;margin-bottom:2rem;padding-bottom:1rem;border-bottom:0.5px solid #9B2335;flex-wrap:wrap;gap:1rem;}
.dash-title{font-family:'Cormorant Garamond',serif;font-size:1.8rem;color:#E8D5A3;}
.dash-sub{font-size:0.55rem;letter-spacing:0.2em;text-transform:uppercase;color:#E8B4BB;margin-top:0.2rem;}
.dash-btns{display:flex;gap:0.75rem;}
.d-btn{background:none;border:0.5px solid #9B2335;color:#E8B4BB;font-family:'Josefin Sans',sans-serif;font-size:0.55rem;letter-spacing:0.15em;text-transform:uppercase;padding:0.5rem 1rem;cursor:pointer;transition:all 0.2s;}
.d-btn:hover{border-color:#C9A84C;color:#E8D5A3;}
.stats-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(130px,1fr));gap:1rem;margin-bottom:2rem;}
.stat{background:#5C1523;border:0.5px solid #9B2335;border-radius:2px;padding:1.25rem;text-align:center;}
.stat-num{font-family:'Cormorant Garamond',serif;font-size:2.5rem;font-weight:300;color:#E8D5A3;display:block;line-height:1;}
.stat-label{font-size:0.55rem;letter-spacing:0.2em;text-transform:uppercase;color:#E8B4BB;margin-top:0.4rem;display:block;}
.filters{display:flex;gap:0.75rem;margin-bottom:1.5rem;flex-wrap:wrap;}
.filter-btn{background:none;border:0.5px solid #9B2335;color:#E8B4BB;font-family:'Josefin Sans',sans-serif;font-size:0.55rem;letter-spacing:0.15em;text-transform:uppercase;padding:0.45rem 1rem;cursor:pointer;transition:all 0.2s;border-radius:1px;}
.filter-btn.active{background:#7D1D30;border-color:#7D1D30;color:#FDF6EE;}
.rsvp-list{display:flex;flex-direction:column;gap:1rem;}
.rsvp-card-d{background:#5C1523;border:0.5px solid #9B2335;border-radius:2px;padding:1.25rem 1.5rem;display:flex;align-items:flex-start;gap:1.25rem;flex-wrap:wrap;}
.rsvp-avatar{width:40px;height:40px;border-radius:50%;display:flex;align-items:center;justify-content:center;font-family:'Cormorant Garamond',serif;font-size:1.1rem;font-weight:500;flex-shrink:0;}
.rsvp-avatar.yes{background:#7D1D30;color:#E8D5A3;}
.rsvp-avatar.no{background:#3a1a1a;color:#c97d7d;}
.rsvp-body{flex:1;min-width:180px;}
.rsvp-dname{font-family:'Cormorant Garamond',serif;font-size:1.15rem;color:#E8D5A3;}
.rsvp-demail{font-size:0.6rem;letter-spacing:0.08em;color:#E8B4BB;margin-top:0.1rem;}
.rsvp-meta{display:flex;gap:0.75rem;margin-top:0.6rem;flex-wrap:wrap;}
.badge{font-size:0.55rem;letter-spacing:0.1em;text-transform:uppercase;padding:0.25rem 0.65rem;border-radius:20px;}
.badge-yes{background:#1a3d1a;color:#7dc97d;}
.badge-no{background:#3d1a1a;color:#c97d7d;}
.badge-seats{background:#3D0C11;color:#E8D5A3;border:0.5px solid #9B2335;}
.rsvp-msg-d{font-family:'Cormorant Garamond',serif;font-size:0.9rem;color:#E8B4BB;margin-top:0.5rem;border-left:2px solid #9B2335;padding-left:0.75rem;}
.rsvp-ddate{font-size:0.55rem;letter-spacing:0.08em;color:rgba(232,180,187,0.27);margin-top:0.4rem;}
.empty-state{text-align:center;padding:3rem;color:rgba(232,180,187,0.53);font-size:0.7rem;letter-spacing:0.15em;}

/* ── MOBILE ── */
@media(max-width:600px){
  .nav-links{display:none;}
  .venue-deets{gap:1.5rem;}
  .spotify-card{flex-direction:column;}
  .dash-header{flex-direction:column;align-items:flex-start;}
  .rsvp-btn{padding:16px 40px;font-size:15px;}
  .ent-names{gap:0.4rem 0.8rem;margin-bottom:0.7rem;}
  .ent-name{font-size:1rem;}
  /* .nav{display:none;} */
    .photo-banner{padding-top:0.6rem !important;}   /* phone: nav is hidden, so go right to the top */
  .pb-logo{width:clamp(60px, 16vw, 80px);}
}

/* ════════════════════════════
   ANIMATIONS
════════════════════════════ */

/* Hero entrance */
@keyframes fadeDown{from{opacity:0;transform:translateY(-18px);}to{opacity:1;transform:translateY(0);}}
@keyframes fadeUp{from{opacity:0;transform:translateY(24px);}to{opacity:1;transform:translateY(0);}}
@keyframes fadeIn{from{opacity:0;}to{opacity:1;}}
@keyframes expandWidth{from{width:0;}to{width:120px;}}
@keyframes shimmer{0%,100%{opacity:0.5;}50%{opacity:1;}}

/* Parol slow rotation + float */
@keyframes floatA{0%,100%{transform:translateY(0px) rotate(0deg);}50%{transform:translateY(-18px) rotate(4deg);}}
@keyframes floatB{0%,100%{transform:translateY(0px) rotate(0deg);}50%{transform:translateY(-12px) rotate(-3deg);}}
.parol-a{animation:floatA 9s ease-in-out infinite;}
.parol-b{animation:floatB 12s ease-in-out infinite;}

/* Hero text stagger */
.hero-ornament{animation:fadeDown 0.9s ease both;animation-delay:0.2s;opacity:0;}
.hero-names{animation:fadeUp 1.1s ease both;animation-delay:0.5s;opacity:0;}
.hero-divider{animation:expandWidth 1s ease both;animation-delay:1s;opacity:0;}
.hero-date{animation:fadeUp 0.9s ease both;animation-delay:1.2s;opacity:0;}
.hero-tagline{animation:fadeUp 0.9s ease both;animation-delay:1.4s;opacity:0;}

/* Gold divider shimmer on hover */
.sec-div{transition:opacity 0.3s;}
section:hover .sec-div{animation:shimmer 1.5s ease infinite;}

/* Scroll reveal — elements start hidden */
.reveal{opacity:0;transform:translateY(32px);transition:opacity 0.7s ease,transform 0.7s ease;}
.reveal.visible{opacity:1;transform:translateY(0);}
.reveal-left{opacity:0;transform:translateX(-32px);transition:opacity 0.7s ease,transform 0.7s ease;}
.reveal-left.visible{opacity:1;transform:translateX(0);}
.reveal-right{opacity:0;transform:translateX(32px);transition:opacity 0.7s ease,transform 0.7s ease;}
.reveal-right.visible{opacity:1;transform:translateX(0);}

/* Staggered children */
.stagger > *{opacity:0;transform:translateY(24px);transition:opacity 0.5s ease,transform 0.5s ease;}
.stagger.visible > *{opacity:1;transform:translateY(0);}
.stagger.visible > *:nth-child(1){transition-delay:0.05s;}
.stagger.visible > *:nth-child(2){transition-delay:0.12s;}
.stagger.visible > *:nth-child(3){transition-delay:0.19s;}
.stagger.visible > *:nth-child(4){transition-delay:0.26s;}
.stagger.visible > *:nth-child(5){transition-delay:0.33s;}
.stagger.visible > *:nth-child(6){transition-delay:0.40s;}
.stagger.visible > *:nth-child(7){transition-delay:0.47s;}
.stagger.visible > *:nth-child(8){transition-delay:0.54s;}
.stagger.visible > *:nth-child(9){transition-delay:0.61s;}
.stagger.visible > *:nth-child(10){transition-delay:0.68s;}
.stagger.visible > *:nth-child(11){transition-delay:0.75s;}

/* ── TIMELINE WITH PHOTOS ── */
.timeline{display:flex;flex-direction:column;gap:3.5rem;position:relative;padding:0;}
.timeline::before{content:'';position:absolute;left:50%;top:0;bottom:0;width:1px;background:linear-gradient(180deg,transparent,#E8B4BB,#E8B4BB,transparent);transform:translateX(-50%);}
.t-item{display:grid;grid-template-columns:1fr 60px 1fr;align-items:center;gap:0;position:relative;}
.t-item:nth-child(odd) .t-content{grid-column:1;text-align:right;padding-right:2.5rem;}
.t-item:nth-child(odd) .t-photo{grid-column:3;padding-left:2.5rem;}
.t-item:nth-child(even) .t-content{grid-column:3;text-align:left;padding-left:2.5rem;}
.t-item:nth-child(even) .t-photo{grid-column:1;grid-row:1;padding-right:2.5rem;}
.t-center{grid-column:2;display:flex;justify-content:center;align-items:center;z-index:2;}
.t-dot{width:12px;height:12px;border-radius:50%;background:#C9A84C;border:2px solid #FDF6EE;box-shadow:0 0 0 1px #C9A84C;flex-shrink:0;animation:pulseDot 2.5s ease-in-out infinite;}
.t-year{font-family:'Josefin Sans',sans-serif;font-size:0.6rem;letter-spacing:0.25em;text-transform:uppercase;color:#C9A84C;margin-bottom:0.4rem;}
.t-text{font-family:'Cormorant Garamond',serif;font-size:1.1rem;line-height:1.8;color:#5C1523;}
.t-text strong{color:#7D1D30;font-weight:500;}

/* Photo placeholder */
.t-photo-frame{position:relative;border-radius:2px;overflow:hidden;border:0.5px solid #E8B4BB;aspect-ratio:4/3;background:#F5E6E8;cursor:pointer;transition:transform 0.3s ease,box-shadow 0.3s ease;}
.t-photo-frame:hover{transform:scale(1.02);box-shadow:0 8px 30px rgba(125,29,48,0.15);}
.t-photo-frame img{width:100%;height:100%;object-fit:cover;display:block;}
.t-photo-frame .placeholder{width:100%;height:100%;display:flex;flex-direction:column;align-items:center;justify-content:center;gap:0.75rem;background:linear-gradient(135deg,#F5E6E8 0%,#FDF6EE 50%,#F5E6E8 100%);}
.placeholder-icon{color:#C4908A;opacity:0.6;}
.placeholder-label{font-family:'Josefin Sans',sans-serif;font-size:0.58rem;letter-spacing:0.2em;text-transform:uppercase;color:#C4908A;text-align:center;line-height:1.6;}
.t-photo-frame .corner{position:absolute;width:16px;height:16px;border-color:#C9A84C;border-style:solid;opacity:0.5;}
.t-photo-frame .corner.tl{top:6px;left:6px;border-width:1px 0 0 1px;}
.t-photo-frame .corner.tr{top:6px;right:6px;border-width:1px 1px 0 0;}
.t-photo-frame .corner.bl{bottom:6px;left:6px;border-width:0 0 1px 1px;}
.t-photo-frame .corner.br{bottom:6px;right:6px;border-width:0 1px 1px 0;}

/* Click-to-upload overlay */
.upload-hint{position:absolute;bottom:0;left:0;right:0;background:linear-gradient(transparent,rgba(125,29,48,0.6));color:#FDF6EE;font-family:'Josefin Sans',sans-serif;font-size:0.55rem;letter-spacing:0.15em;text-transform:uppercase;text-align:center;padding:0.75rem 0.5rem 0.5rem;opacity:0;transition:opacity 0.3s;}
.t-photo-frame:hover .upload-hint{opacity:1;}

/* ── TIMELINE FIXES ── */
.t-item{padding-left:0;}
.t-item > *{grid-row:1;}
.t-dot{position:static;}
@keyframes pulseDot{0%,100%{box-shadow:0 0 0 1px #C9A84C;}50%{box-shadow:0 0 0 6px rgba(201,168,76,0.2);}}


@media(max-width:700px){
  .timeline::before{left:20px;}
  .t-item{grid-template-columns:40px 1fr;grid-template-rows:auto auto;}
  .t-item:nth-child(odd) .t-content,.t-item:nth-child(even) .t-content{grid-column:2;grid-row:1;text-align:left;padding-left:1rem;padding-right:0;}
  .t-item:nth-child(odd) .t-photo,.t-item:nth-child(even) .t-photo{grid-column:2;grid-row:2;padding-left:1rem;padding-right:0;margin-top:1rem;}
  .t-center{grid-column:1;grid-row:1;}
}

/* Card hover lift */
.outfit-card{transition:box-shadow 0.3s ease,transform 0.3s ease;}
.outfit-card:hover{transform:translateY(-4px);box-shadow:0 8px 28px rgba(125,29,48,0.13);}
.spon-card{transition:box-shadow 0.3s ease,transform 0.3s ease,border-color 0.3s;}
.spon-card:hover{transform:translateY(-3px);box-shadow:0 6px 20px rgba(125,29,48,0.1);border-color:#C9A84C;}

/* Venue card inner shimmer */
@keyframes venueShimmer{0%{opacity:0.27;}50%{opacity:0.6;}100%{opacity:0.27;}}
.venue-card::before{animation:venueShimmer 4s ease-in-out infinite;}

/* Nav fade in */
.nav{animation:fadeDown 0.6s ease both;}

/* Gold petals */
.petal{position:fixed;pointer-events:none;z-index:0;font-size:1rem;opacity:0;animation:petalFall linear infinite;}
@keyframes petalFall{
  0%{opacity:0;transform:translateY(-20px) rotate(0deg);}
  10%{opacity:0.6;}
  90%{opacity:0.3;}
  100%{opacity:0;transform:translateY(100vh) rotate(360deg);}
}

/* Section label fade */
.sec-label{animation:none;}

  /* CSS-drawn diamonds, same on every browser */
.sec-label,
.hero-ornament,
.pb-ornament{
  display:flex;
  align-items:center;
  justify-content:center;
  gap:0.9em;
}
.sec-label::before,.sec-label::after,
.hero-ornament::before,.hero-ornament::after,
.pb-ornament::before,.pb-ornament::after{
  content:'';
  width:0.5em;
  height:0.5em;
  background:currentColor;       /* uses the gold text color */
  transform:rotate(45deg);
  flex-shrink:0;
}

/* the diamond in the venue divider */
.venue-divider-diamond{
  display:block;
  width:7px;
  height:7px;
  background:#C9A84C;
  transform:rotate(45deg);
  font-size:0;                   /* hides any leftover character */
}

/* Footer names glow */
@keyframes gentleGlow{0%,100%{text-shadow:none;}50%{text-shadow:0 0 20px rgba(201,168,76,0.3);}}
.footer-names{animation:gentleGlow 4s ease-in-out infinite;}
/* ── SUBTLE SECTION SEPARATION ── */
section{position:relative;}
section::after{
  content:'';
  position:absolute;
  bottom:0;left:0;right:0;
  height:80px;
  pointer-events:none;
  z-index:2;
}
#our-story::after{background:linear-gradient(to bottom,transparent,rgba(125,29,48,0.04));}
#location::after{background:linear-gradient(to bottom,transparent,rgba(61,12,17,0.18));}
#outfit::after{background:linear-gradient(to bottom,transparent,rgba(201,168,76,0.05));}
#playlist::after{background:linear-gradient(to bottom,transparent,rgba(92,21,35,0.05));}
#sponsors::after{background:linear-gradient(to bottom,transparent,rgba(30,5,8,0.25));}
#prenup::after{background:linear-gradient(to bottom,transparent,rgba(10,2,3,0.3));}
#entourage::after{background:linear-gradient(to bottom,transparent,rgba(61,12,17,0.04));}

/* top fade into each section */
section::before{
  content:'';
  position:absolute;
  top:0;left:0;right:0;
  height:60px;
  pointer-events:none;
  z-index:2;
}
.hero::before{background-image:radial-gradient(ellipse at 20% 50%,#F5E6E888 0%,transparent 60%),radial-gradient(ellipse at 80% 20%,#E8D5A344 0%,transparent 50%);}
#our-story::before{background:linear-gradient(to bottom,rgba(240,232,224,0.0),transparent);}
#location::before{background:linear-gradient(to bottom,rgba(125,29,48,0.15),transparent);}
#outfit::before{background:linear-gradient(to bottom,rgba(253,246,238,0.0),transparent);}
#playlist::before{background:linear-gradient(to bottom,rgba(240,232,224,0.0),transparent);}
#sponsors::before{background:linear-gradient(to bottom,rgba(92,21,35,0.2),transparent);}
#prenup::before{background:linear-gradient(to bottom,rgba(61,12,17,0.2),transparent);}
#entourage::before{background:linear-gradient(to bottom,rgba(253,246,238,0.0),transparent);}

/* ── MINI SPOTIFY PLAYER ── */
/* ── VINYL PLAYER ── */
.vinyl-player{
  position:fixed;
  bottom:2rem;right:2rem;
  z-index:999;
  width:260px;
  background:linear-gradient(145deg,#1a0508,#2d0a10,#1a0508);
  border-radius:16px;
  padding:1.25rem;
  box-shadow:0 12px 40px rgba(0,0,0,0.5),0 0 0 0.5px rgba(201,168,76,0.2);
  transform:translateY(calc(100% + 3rem));
  transition:transform 0.5s cubic-bezier(0.34,1.56,0.64,1);
  overflow:hidden;
}
.vinyl-player.visible{transform:translateY(0);}

/* Vinyl disc */
.vinyl-wrap{position:relative;width:130px;height:130px;margin:0 auto 1rem;}
.vinyl-disc{
  width:130px;height:130px;border-radius:50%;
  background:
    radial-gradient(circle at 50% 50%, #1a0508 0%, #1a0508 14%,
      transparent 14.5%, transparent 15.5%,
      #111 15.5%, #111 17%,
      #0d0d0d 17%, #0d0d0d 100%);
  box-shadow:0 4px 20px rgba(0,0,0,0.6),inset 0 0 20px rgba(0,0,0,0.4);
  animation:vinylSpin 4s linear infinite;
  animation-play-state:paused;
  position:relative;
}
.vinyl-disc.playing{animation-play-state:running;}
/* Vinyl grooves */
.vinyl-disc::before{
  content:'';
  position:absolute;inset:0;
  border-radius:50%;
  background:repeating-radial-gradient(
    circle at 50% 50%,
    transparent 0px,
    transparent 3px,
    rgba(255,255,255,0.025) 3px,
    rgba(255,255,255,0.025) 4px
  );
}
/* Center label */
.vinyl-disc::after{
  content:'J & J';
  position:absolute;
  top:50%;left:50%;
  transform:translate(-50%,-50%);
  width:36px;height:36px;
  background:radial-gradient(circle,#7D1D30,#5C1523);
  border-radius:50%;
  display:flex;align-items:center;justify-content:center;
  font-family:'Pinyon Script',cursive;
  font-size:0.6rem;color:#E8D5A3;
  box-shadow:0 0 0 2px rgba(201,168,76,0.3);
  line-height:36px;
  text-align:center;
}
/* Tonearm */
.vinyl-arm{
  position:absolute;
  top:-8px;right:-8px;
  width:58px;height:58px;
  transform-origin:90% 10%;
  transition:transform 0.8s ease;
}
.vinyl-arm.playing{transform:rotate(-18deg);}
.vinyl-arm svg{width:100%;height:100%;}

@keyframes vinylSpin{from{transform:rotate(0deg);}to{transform:rotate(360deg);}}

/* Track info */
.vinyl-info{text-align:center;margin-bottom:0.9rem;}
.vinyl-track{font-family:'Cormorant Garamond',serif;font-size:0.85rem;color:#E8D5A3;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;font-style:italic;}
.vinyl-artist{font-family:'Josefin Sans',sans-serif;font-size:0.5rem;letter-spacing:0.15em;text-transform:uppercase;color:#C4908A;margin-top:0.2rem;}

/* Controls */
.vinyl-controls{display:flex;align-items:center;justify-content:center;gap:0.75rem;}
.vinyl-btn{background:none;border:0.5px solid rgba(232,180,187,0.2);color:#E8B4BB;width:32px;height:32px;border-radius:50%;cursor:pointer;display:flex;align-items:center;justify-content:center;transition:all 0.2s;font-size:0.75rem;}
.vinyl-btn:hover{border-color:#C9A84C;color:#E8D5A3;}
.vinyl-btn.play-btn{width:40px;height:40px;background:linear-gradient(135deg,#7D1D30,#9B2335);border:none;color:#E8D5A3;font-size:1rem;box-shadow:0 4px 12px rgba(125,29,48,0.4);}
.vinyl-btn.play-btn:hover{background:linear-gradient(135deg,#9B2335,#C45C6A);transform:scale(1.05);}

/* Gold top bar */
.vinyl-header{display:flex;align-items:center;justify-content:space-between;margin-bottom:1rem;}
.vinyl-label{font-family:'Josefin Sans',sans-serif;font-size:0.48rem;letter-spacing:0.2em;text-transform:uppercase;color:#C9A84C;}
.vinyl-close{background:none;border:none;color:rgba(232,180,187,0.4);font-size:0.75rem;cursor:pointer;padding:0;line-height:1;transition:color 0.2s;}
.vinyl-close:hover{color:#C9A84C;}

/* Reopen pill */
.vinyl-reopen{
  position:fixed;bottom:2rem;right:2rem;z-index:999;
  background:linear-gradient(135deg,#1a0508,#2d0a10);
  border:0.5px solid rgba(201,168,76,0.35);
  color:#E8D5A3;font-family:'Josefin Sans',sans-serif;font-size:0.5rem;
  letter-spacing:0.15em;text-transform:uppercase;
  padding:0.5rem 1rem;border-radius:20px;cursor:pointer;
  display:none;align-items:center;gap:0.4rem;
  box-shadow:0 4px 16px rgba(0,0,0,0.3);
  transition:all 0.2s;
}
.vinyl-reopen:hover{border-color:#C9A84C;}
.vinyl-reopen.show{display:flex;}

/* Hidden spotify iframe */
#spotify-hidden-frame{
  position:fixed;bottom:-9999px;left:-9999px;
  width:1px;height:1px;opacity:0;
  pointer-events:none;z-index:-1;
  border:none;
}
.music-bar{display:none!important;}
/* Remove old music bar from nav */
.music-bar{display:none!important;}
.music-bar:hover{border-color:#C9A84C;}
.music-bar-label{font-family:'Josefin Sans',sans-serif;font-size:0.55rem;letter-spacing:0.15em;text-transform:uppercase;color:#9B2335;}
@keyframes barBounce{0%,100%{height:4px;}50%{height:12px;}}
.bar{width:2px;background:#C9A84C;border-radius:2px;animation:barBounce 0.8s ease-in-out infinite;}
.bar:nth-child(2){animation-delay:0.15s;}
.bar:nth-child(3){animation-delay:0.3s;}
.bar:nth-child(4){animation-delay:0.45s;}
.music-bar.paused .bar{animation-play-state:paused;height:4px;}
/* ── PRENUP GALLERY ── */
.prenup-featured{display:grid;grid-template-columns:1fr 1fr;grid-template-rows:320px 320px;gap:8px;margin-bottom:8px;}
.prenup-featured .pf-item{position:relative;overflow:hidden;border-radius:2px;cursor:pointer;}
.prenup-featured .pf-item:first-child{grid-row:1/3;grid-column:1;}
.prenup-featured .pf-item:nth-child(2){grid-row:1;grid-column:2;}
.prenup-featured .pf-item:nth-child(3){grid-row:2;grid-column:2;}
.prenup-featured .pf-item:nth-child(4){display:none;}

.prenup-album{display:grid;grid-template-columns:repeat(auto-fill,minmax(180px,1fr));gap:8px;margin-top:8px;}
.prenup-album .pa-item{position:relative;overflow:hidden;border-radius:2px;cursor:pointer;aspect-ratio:3/4;}

.pf-item img,.pa-item img{width:100%;height:100%;object-fit:cover;display:block;transition:transform 0.5s ease;}
.pf-item:hover img,.pa-item:hover img{transform:scale(1.05);}

/* Placeholder style for prenup */
.prenup-placeholder{width:100%;height:100%;display:flex;flex-direction:column;align-items:center;justify-content:center;gap:0.6rem;background:linear-gradient(135deg,#F5E6E8,#FDF6EE,#F5E6E8);border:0.5px dashed #C4908A;}
.prenup-placeholder svg{color:#C4908A;opacity:0.5;}
.prenup-placeholder span{font-family:'Josefin Sans',sans-serif;font-size:0.55rem;letter-spacing:0.15em;text-transform:uppercase;color:#C4908A;text-align:center;line-height:1.7;}

/* Gold overlay on hover */
.pf-item::after,.pa-item::after{content:'';position:absolute;inset:0;background:linear-gradient(to top,rgba(61,12,17,0.4) 0%,transparent 50%);opacity:0;transition:opacity 0.3s;}
.pf-item:hover::after,.pa-item:hover::after{opacity:1;}
.pf-item .zoom-hint,.pa-item .zoom-hint{position:absolute;bottom:12px;left:50%;transform:translateX(-50%);font-family:'Josefin Sans',sans-serif;font-size:0.55rem;letter-spacing:0.2em;text-transform:uppercase;color:#FDF6EE;opacity:0;transition:opacity 0.3s;z-index:2;white-space:nowrap;}
.pf-item:hover .zoom-hint,.pa-item:hover .zoom-hint{opacity:1;}

/* Album toggle */
.album-toggle{display:flex;align-items:center;justify-content:center;gap:1rem;margin:2rem 0 1rem;}
.album-toggle-btn{font-family:'Josefin Sans',sans-serif;font-size:0.62rem;letter-spacing:0.2em;text-transform:uppercase;color:#7D1D30;background:none;border:0.5px solid #E8B4BB;padding:0.65rem 1.8rem;cursor:pointer;transition:all 0.25s;border-radius:1px;}
.album-toggle-btn:hover{border-color:#C9A84C;color:#C9A84C;}
.album-toggle-btn.active{background:#7D1D30;color:#FDF6EE;border-color:#7D1D30;}
.album-count{font-family:'Josefin Sans',sans-serif;font-size:0.55rem;letter-spacing:0.1em;color:#C4908A;}

/* Lightbox */
.lightbox{position:fixed;inset:0;background:rgba(30,5,8,0.95);z-index:9999;display:none;align-items:center;justify-content:center;padding:2rem;}
.lightbox.open{display:flex;}
.lightbox-img{max-width:90vw;max-height:88vh;object-fit:contain;border-radius:2px;box-shadow:0 20px 60px rgba(0,0,0,0.5);}
.lightbox-close{position:absolute;top:1.5rem;right:1.5rem;background:none;border:0.5px solid rgba(232,180,187,0.4);color:#E8B4BB;width:40px;height:40px;border-radius:50%;font-size:1.2rem;cursor:pointer;display:flex;align-items:center;justify-content:center;transition:all 0.2s;}
.lightbox-close:hover{border-color:#C9A84C;color:#C9A84C;}
.lightbox-prev,.lightbox-next{position:absolute;top:50%;transform:translateY(-50%);background:none;border:0.5px solid rgba(232,180,187,0.3);color:#E8B4BB;width:44px;height:44px;border-radius:50%;font-size:1.3rem;cursor:pointer;display:flex;align-items:center;justify-content:center;transition:all 0.2s;}
.lightbox-prev{left:1.5rem;}.lightbox-next{right:1.5rem;}
.lightbox-prev:hover,.lightbox-next:hover{border-color:#C9A84C;color:#C9A84C;}
.lightbox-counter{position:absolute;bottom:1.5rem;left:50%;transform:translateX(-50%);font-family:'Josefin Sans',sans-serif;font-size:0.6rem;letter-spacing:0.2em;color:#E8B4BB;}
.lightbox-caption{position:absolute;bottom:3rem;left:50%;transform:translateX(-50%);font-family:'Cormorant Garamond',serif;font-size:1rem;color:#E8D5A3;text-align:center;white-space:nowrap;}


  @media(max-width:700px){
  .prenup-featured{grid-template-columns:1fr;grid-template-rows:260px 200px 200px;}
  .prenup-featured .pf-item:first-child{grid-row:1;grid-column:1;}
  .prenup-featured .pf-item:nth-child(2){grid-row:2;grid-column:1;}
  .prenup-featured .pf-item:nth-child(3){grid-row:3;grid-column:1;}
  .prenup-album{grid-template-columns:repeat(2,1fr);}
}

/* Global canvas layer */
.bg-canvas{position:fixed;top:0;left:0;width:100%;height:100%;pointer-events:none;z-index:0;overflow:hidden;}
.page.active .bg-canvas{display:block;}

/* Wave animations */
@keyframes waveFlow1{0%{transform:translateX(0) translateY(0);}50%{transform:translateX(-60px) translateY(20px);}100%{transform:translateX(0) translateY(0);}}
@keyframes waveFlow2{0%{transform:translateX(0) translateY(0);}50%{transform:translateX(60px) translateY(-15px);}100%{transform:translateX(0) translateY(0);}}
@keyframes waveFlow3{0%{transform:translateX(0) translateY(0);}50%{transform:translateX(-40px) translateY(30px);}100%{transform:translateX(0) translateY(0);}}

.wave1{animation:waveFlow1 18s ease-in-out infinite;}
.wave2{animation:waveFlow2 22s ease-in-out infinite;}
.wave3{animation:waveFlow3 26s ease-in-out infinite;}

/* Bloom animations */
@keyframes bloomPulse{0%,100%{transform:scale(1) rotate(0deg);opacity:0.04;}50%{transform:scale(1.15) rotate(15deg);opacity:0.07;}}
@keyframes bloomDrift{0%,100%{transform:scale(1) rotate(0deg) translate(0,0);opacity:0.05;}33%{transform:scale(1.1) rotate(10deg) translate(12px,-8px);opacity:0.08;}66%{transform:scale(0.95) rotate(-8deg) translate(-8px,12px);opacity:0.04;}}
@keyframes bloomSpin{0%{transform:rotate(0deg) scale(1);}100%{transform:rotate(360deg) scale(1);}}

.bloom1{animation:bloomPulse 12s ease-in-out infinite;}
.bloom2{animation:bloomDrift 16s ease-in-out infinite;}
.bloom3{animation:bloomPulse 20s ease-in-out infinite reverse;}
.bloom4{animation:bloomDrift 14s ease-in-out infinite 3s;}
.bloom5{animation:bloomPulse 18s ease-in-out infinite 6s;}

/* Section bg overlays */
.sec-bg{position:absolute;inset:0;overflow:hidden;pointer-events:none;z-index:0;}
section{position:relative;}
section .inner{position:relative;z-index:1;}
.hero .hero-inner{position:relative;z-index:2;}

/* Orb blobs */
@keyframes orbFloat1{0%,100%{transform:translate(0,0) scale(1);}33%{transform:translate(30px,-20px) scale(1.08);}66%{transform:translate(-20px,25px) scale(0.95);}}
@keyframes orbFloat2{0%,100%{transform:translate(0,0) scale(1);}33%{transform:translate(-25px,15px) scale(1.05);}66%{transform:translate(20px,-30px) scale(0.98);}}
@keyframes orbFloat3{0%,100%{transform:translate(0,0) scale(1);}50%{transform:translate(15px,20px) scale(1.1);}}

.orb{position:absolute;border-radius:50%;filter:blur(60px);pointer-events:none;}
.orb1{animation:orbFloat1 20s ease-in-out infinite;}
.orb2{animation:orbFloat2 25s ease-in-out infinite 3s;}
.orb3{animation:orbFloat3 18s ease-in-out infinite 6s;}


.rsvp-wrap {
  text-align: center;
  margin-top: 14px;
}

.rsvp-btn {
  display: inline-block;
  padding: 18px 52px;
  background: #7b1e34;
  color: #fcf5ee;
  border: 2px solid #c9a84c;
  border-radius: 999px;
  font-family: inherit;
  font-size: 16px;
  font-weight: 600;
  letter-spacing: 3px;
  text-decoration: none;
  box-shadow: 0 6px 18px rgba(123, 30, 52, 0.3);
  transition: transform 0.2s ease, background 0.2s ease, box-shadow 0.2s ease;
}

.rsvp-btn:hover {
  background: #5f1727;
  transform: translateY(-2px);
  box-shadow: 0 6px 16px rgba(123, 30, 52, 0.35);
}

  .ent-photo{
  display:flex;
  align-items:center;
  justify-content:center;
  gap:clamp(1rem, 4vw, 3rem);
  width:100%;
  max-width:760px;
  margin:1.8rem auto 0;
  padding:0 1rem;
  text-align:left;
}
.ent-photo img{
  width:clamp(120px, 32vw, 300px);
  height:auto;
  flex-shrink:0;
  display:block;
  filter:drop-shadow(0 8px 12px rgba(123,30,52,0.18));
}
.ent-photo figcaption{
  flex:1;
  max-width:320px;
  display:flex;
  flex-direction:column;
  gap:0.5rem;
}
.outfit-title{
  font-family:'Josefin Sans',sans-serif;
  font-size:clamp(0.8rem, 2.6vw, 1.05rem);   /* was 0.55–0.7rem */
  letter-spacing:0.25em;
  text-transform:uppercase;
  color:#C9A84C;
  padding-bottom:0.7rem;
  border-bottom:1px solid rgba(201,168,76,0.4);
  margin-bottom:0.3rem;
}
.guest-photo .outfit-title{
  display:block;
  width:fit-content;
  margin:0 auto 0;          /* was 0 auto 1.2rem */
}
.outfit-line{
  font-family:'Cormorant Garamond',serif;
  font-size:clamp(0.95rem, 2.6vw, 1.25rem);
  color:#5C1523;
  line-height:1.4;
}
.outfit-line strong{
  font-weight:600;
  color:#7D1D30;
}

.guest-photo{
  width:clamp(270px, 72vw, 675px);   /* 2.25 × the couple image width */
  max-width:100%;
  margin:1.8rem auto 0;
  padding:0;
  text-align:center;
}

  
  
.guest-photo img{
  width:100%;
  height:auto;
  display:block;
  margin-top:-4%;           /* pulls the image up under the title */
  filter:drop-shadow(0 8px 12px rgba(123,30,52,0.15));
}
.guest-photo figcaption{
  display:flex;
  flex-direction:column;
  gap:0.4rem;
 margin-top:0.2rem;      /* was 1rem */
}

.guest-msg{
  font-family:'Cormorant Garamond',serif;
  font-size:clamp(1rem, 3.5vw, 1.25rem);
  color:#5C1523;
  line-height:1.5;
}
.guest-hues{
  font-family:'Cormorant Garamond',serif;
  font-style:italic;
  font-size:clamp(0.95rem, 3.2vw, 1.1rem);
  color:#9A6B75;
  line-height:1.5;
}

.rsvp-note{
  width:100%;
  max-width:560px;
  margin:2.5rem auto 1.8rem;
  padding:0 1.2rem;
  text-align:center;
}
.rsvp-note p{
  font-family:'Cormorant Garamond',serif;
  font-size:clamp(1rem, 3.4vw, 1.2rem);
  color:#5C1523;
  line-height:1.7;
  margin:0 0 1rem;
}
.rsvp-note strong{
  font-weight:600;
  color:#7D1D30;
}
.rsvp-seats{
  font-size:clamp(1.15rem, 4vw, 1.4rem) !important;
  color:#7D1D30 !important;
}
.seat-blank{
  display:inline-block;
  min-width:2.8rem;
  border-bottom:1.5px solid #C9A84C;
  text-align:center;
}
.rsvp-warning{
  position:relative;
  font-style:italic;
  color:#9A6B75 !important;
  border-top:none;
  margin-top:2rem !important;
  padding-top:2.2rem;
}
/* the fading line, with a gap in the middle for the diamond */
.rsvp-warning::before{
  content:'';
  position:absolute;
  top:0;
  left:50%;
  width:min(300px, 80%);
  height:1px;
  transform:translateX(-50%);
  background:linear-gradient(90deg,
    transparent 0%,
    rgba(201,168,76,0.75) 30%,
    transparent 46%,
    transparent 54%,
    rgba(201,168,76,0.75) 70%,
    transparent 100%);
}
/* the diamond */
.rsvp-warning::after{
  content:'';
  position:absolute;
  top:0;
  left:50%;
  width:7px;
  height:7px;
  background:#C9A84C;
  transform:translate(-50%,-50%) rotate(45deg);
}
.rsvp-thanks{
  font-size:clamp(1.05rem, 3.6vw, 1.3rem) !important;
  color:#7D1D30 !important;
  margin-bottom:0 !important;
}

  .rsvp-logo{
  text-align:center;
  margin:0 auto 1.5rem;
}
.rsvp-logo img{
  width:clamp(110px, 24vw, 190px);
  height:auto;
  display:inline-block;
  filter:drop-shadow(0 6px 10px rgba(123,30,52,0.15));
}

  #entourage{padding-bottom:2rem;}
.gift-note{
  width:100%;
  max-width:560px;
  margin:0 auto;
  padding:0 1.2rem 2rem;
  text-align:center;

}
.gift-title{
  font-family:'Josefin Sans',sans-serif;
  font-size:clamp(0.6rem, 2vw, 0.7rem);
  letter-spacing:0.3em;
  text-transform:uppercase;
  color:#C9A84C;
  margin-bottom:1rem;
}
.gift-note p:not(.gift-title){
  font-family:'Cormorant Garamond',serif;
  font-style:italic;
  font-size:clamp(1rem, 3.4vw, 1.2rem);
  color:#5C1523;
  line-height:1.7;
  margin:0 0 0.8rem;
}
.gift-note strong{
  font-style:normal;
  font-weight:600;
  color:#7D1D30;
}

.hero-tagline{margin-top:0.8rem;}   /* space between the date and the tagline */

.hero-logo{
  display:block;
  margin:1.8rem auto 0;
  width:clamp(90px, 16vw, 140px);
  height:auto;
  opacity:0;
  animation:fadeUp 1s ease both;
  animation-delay:1.7s;
}

  /* ── PHOTO BANNER ── */
.photo-banner{
  position:relative;
  min-height:100vh;
  min-height:100svh;
padding-top:68px;            /* was 84px, moves the logo up */
  display:flex;
  flex-direction:column;
  align-items:center;
  justify-content:space-between;
  text-align:center;
  background:#2a0d12;
  overflow:hidden;
}
  .photo-banner{padding-bottom:4rem;}
.pb-img{
  position:absolute;
  inset:0;
  width:100%;
  height:100%;
  object-fit:cover;
  object-position:center 30%;
  z-index:0;
  animation:fadeIn 1.6s ease both;
}
.pb-shade{
  position:absolute;
  inset:0;
  z-index:1;
  background:linear-gradient(to bottom, rgba(30,5,8,0.5) 0%, rgba(30,5,8,0.15) 40%, rgba(30,5,8,0.78) 100%);
}

.pb-logo{
  position:relative;
  z-index:2;
  width:clamp(70px, 11vw, 110px);
  height:auto;
  filter:drop-shadow(0 2px 8px rgba(0,0,0,0.4)) opacity(0.7);   /* 0.7 = softer */
  animation:fadeDown 1s ease both;
  animation-delay:0.4s;
}
.pb-bottom{
  position:relative;
  z-index:2;
}
/* ── PHOTO BANNER ── */
.photo-banner{
  position:relative;
  min-height:100vh;
  min-height:100svh;
  padding:84px 1.5rem 2.5rem;
  display:flex;
  flex-direction:column;
  align-items:center;
  justify-content:space-between;
  text-align:center;
  background:#2a0d12;
  overflow:hidden;
}
.pb-img{
  position:absolute;
  inset:0;
  width:100%;
  height:100%;
  object-fit:cover;
  object-position:center 30%;
  z-index:0;
  animation:fadeIn 1.6s ease both;
}
.pb-shade{
  position:absolute;
  inset:0;
  z-index:1;
  background:linear-gradient(to bottom, rgba(30,5,8,0.5) 0%, rgba(30,5,8,0.15) 40%, rgba(30,5,8,0.78) 100%);
}
.pb-logo{
  position:relative;
  z-index:2;
  width:clamp(70px, 11vw, 110px);
  height:auto;
  filter:drop-shadow(0 2px 8px rgba(0,0,0,0.4));
  animation:fadeDown 1s ease both;
  animation-delay:0.4s;
}

/* Names on the left and right of the couple */
.pb-names-row{
  display:flex;
  justify-content:center;
  align-items:baseline;            /* keeps the & level with the first names */
  gap:clamp(0.8rem, 3.5vw, 2.5rem);
  margin-bottom:1.6rem;
}
.pb-amp{
  font-family:'Pinyon Script',cursive;
  font-size:clamp(2.4rem, 7vw, 5.5rem);
  line-height:1;
  color:#E8D5A3;
  text-shadow:0 2px 16px rgba(0,0,0,0.45);
  animation:fadeUp 1.1s ease both;
  animation-delay:0.9s;
}
.pb-person{
  animation:fadeUp 1.1s ease both;
  animation-delay:0.8s;
}
.pb-first{
  font-family:'Pinyon Script',cursive;
  font-size:clamp(2.6rem, 8vw, 6.5rem);
  line-height:1.05;
  color:#FDF6EE;
  text-shadow:0 2px 20px rgba(0,0,0,0.45);
}
.pb-last{
  font-family:'Cormorant Garamond',serif;
  font-weight:500;
  font-size:clamp(0.7rem, 1.7vw, 1.05rem);
  letter-spacing:0.4em;
  text-transform:uppercase;
  color:#E8D5A3;
  margin-top:0.4rem;
  padding-left:0.4em;     /* balances the letter-spacing so it looks centered */
  text-shadow:0 1px 8px rgba(0,0,0,0.5);
}

/* Bottom invitation text */
.pb-bottom{
  position:relative;
  z-index:2;
}
.pb-ornament{
  font-family:'Josefin Sans',sans-serif;
  font-size:clamp(0.55rem, 1.8vw, 0.7rem);
  letter-spacing:0.35em;
  text-transform:uppercase;
  color:#E8D5A3;
  margin-bottom:0.9rem;
  animation:fadeUp 0.9s ease both;
  animation-delay:1s;
}
.pb-date{
  font-family:'Josefin Sans',sans-serif;
  font-size:clamp(0.6rem, 2vw, 0.85rem);
  letter-spacing:0.3em;
  text-transform:uppercase;
  color:#FDF6EE;
  text-shadow:0 1px 10px rgba(0,0,0,0.5);
  animation:fadeUp 0.9s ease both;
  animation-delay:1.2s;
}
.pb-tagline{
  font-family:'Cormorant Garamond',serif;
  font-style:italic;
  font-size:clamp(1.1rem, 3.2vw, 1.6rem);
  color:#E8D5A3;
  margin-top:0.6rem;
  animation:fadeUp 0.9s ease both;
  animation-delay:1.4s;
}
.pb-scroll{
  display:inline-block;
  margin-top:1.2rem;
  color:#E8D5A3;
  font-size:1.3rem;
  text-decoration:none;
  animation:fadeIn 1s ease both 1.8s, floatA 3s ease-in-out infinite 2.8s;
}



  a.nav-brand{text-decoration:none;}

  /* ── BANNER LOGO: higher + softer (keep this LAST in <style>) ── */
.pb-logo{
  filter:drop-shadow(0 2px 8px rgba(0,0,0,0.4)) opacity(0.5) !important;   /* was 0.7, lower = more faded */
}
.photo-banner{
  padding-top:68px !important;       /* desktop: just under the 60px nav */
}

  /* ── RSVP SEARCH BOX ── */
.rsvp-search{
  width:100%;
  max-width:480px;
  margin:0 auto 1.6rem;
  padding:0 1.2rem;
  text-align:left;
}
.rf-label{
  display:block;
  font-family:'Josefin Sans',sans-serif;
  font-size:0.62rem;
  letter-spacing:0.22em;
  text-transform:uppercase;
  color:#9B2335;
  margin-bottom:0.55rem;
}
.rf-row{display:flex;gap:0.6rem;}
.rf-row input{
  flex:1;
  min-width:0;
  padding:0.95rem 1.1rem;
  background:#FDF6EE;
  border:1px solid #E8B4BB;
  border-radius:999px;
  outline:none;
  font-family:'Cormorant Garamond',serif;
  font-size:1.2rem;
  color:#3D0C11;
  transition:border-color .2s, box-shadow .2s;
}
.rf-row input::placeholder{color:#B58A8C;font-style:italic;}
.rf-row input:focus{
  border-color:#C9A84C;
  box-shadow:0 0 0 3px rgba(201,168,76,0.2);
}
.rf-btn{
  flex-shrink:0;
  padding:0 1.6rem;
  background:#7D1D30;
  color:#FDF6EE;
  border:1.5px solid #C9A84C;
  border-radius:999px;
  cursor:pointer;
  font-family:'Josefin Sans',sans-serif;
  font-size:0.68rem;
  letter-spacing:0.2em;
  text-transform:uppercase;
  transition:background .2s, transform .2s;
}
.rf-btn:hover{background:#5F1727;transform:translateY(-1px);}
.rf-btn:focus-visible{outline:2px solid #C9A84C;outline-offset:2px;}
@media(max-width:420px){
  .rf-row{flex-direction:column;}
  .rf-btn{padding:0.95rem;}
}

  .rf-hint{
  margin-top:0.7rem;
  text-align:center;
  font-family:'Cormorant Garamond',serif;
  font-size:1.05rem;
  color:#B3261E;
}
button.rsvp-btn{
  cursor:pointer;
  font-family:inherit;
}

  /* ── RSVP DROPDOWN ── */
#rsvp .inner{position:relative;z-index:3;}   /* keeps the dropdown above the section's bottom fade */
.rf-live{position:relative;}
.rf-list{
  position:absolute;
  top:calc(100% + 0.4rem);
  left:0; right:0;
  z-index:20;
  list-style:none;
  margin:0; padding:0.3rem;
  background:#fff;
  border:1px solid #E8B4BB;
  border-radius:18px;
  box-shadow:0 12px 30px rgba(125,29,48,0.15);
  text-align:left;
}
.rf-opt{
  padding:0.8rem 1rem;
  border-radius:14px;
  cursor:pointer;
  font-family:'Cormorant Garamond',serif;
  font-size:1.15rem;
  line-height:1.4;
  color:#5C1523;
}
.rf-opt:hover,
.rf-opt[aria-selected="true"]{background:#F5E6E8;}
.rf-opt strong{color:#7D1D30;font-weight:600;}
.rf-opt small{
  display:block;
  font-family:'Josefin Sans',sans-serif;
  font-size:0.56rem;
  letter-spacing:0.15em;
  text-transform:uppercase;
  color:#C9A84C;
}
.rf-note{padding:0.8rem 1rem;font-style:italic;color:#9A6B75;font-family:'Cormorant Garamond',serif;}
  /* ── RSVP DROPDOWN ── */
#rsvp .inner{position:relative;z-index:3;}
.rf-live{position:relative;}
.rf-list{
  position:absolute;
  top:calc(100% + 0.4rem);
  left:0; right:0;
  z-index:20;
  list-style:none;
  margin:0; padding:0.3rem;
  background:#fff;
  border:1px solid #E8B4BB;
  border-radius:18px;
  box-shadow:0 12px 30px rgba(125,29,48,0.15);
  text-align:left;
}
.rf-opt{padding:0.8rem 1rem;border-radius:14px;cursor:pointer;font-family:'Cormorant Garamond',serif;font-size:1.15rem;line-height:1.4;color:#5C1523;}
.rf-opt:hover,.rf-opt[aria-selected="true"]{background:#F5E6E8;}
.rf-opt strong{color:#7D1D30;font-weight:600;}
.rf-opt small{display:block;font-family:'Josefin Sans',sans-serif;font-size:0.56rem;letter-spacing:0.15em;text-transform:uppercase;color:#C9A84C;}
.rf-note{padding:0.8rem 1rem;font-style:italic;color:#9A6B75;font-family:'Cormorant Garamond',serif;}

/* ── YOUR GROUP ── */
.rsvp-panel{
  width:100%;
  max-width:480px;
  margin:2rem auto 0;
  padding:0 1.2rem;
  text-align:left;
}
.rp-title{
  font-family:'Cormorant Garamond',serif;
  font-style:italic;
  font-size:clamp(1.8rem,5.5vw,2.4rem);
  line-height:1.15;
  color:#7D1D30;
  text-align:center;
}
.rp-sub{
  font-family:'Cormorant Garamond',serif;
  font-size:clamp(1.05rem,3.4vw,1.2rem);
  color:#5C1523;
  text-align:center;
  margin:0.5rem 0 1.2rem;
}
.rp-member{
  display:flex;
  align-items:center;
  justify-content:space-between;
  gap:0.8rem 1rem;
  flex-wrap:wrap;
  padding:1rem 0;
  border-bottom:1px solid rgba(201,168,76,0.35);
}
.rp-name{font-family:'Cormorant Garamond',serif;font-size:1.3rem;color:#5C1523;}
.rp-toggle{
  display:inline-flex;
  padding:3px;
  background:#FDF6EE;
  border:1px solid #E8B4BB;
  border-radius:999px;
}
.rp-toggle label{cursor:pointer;}
.rp-toggle input{position:absolute;opacity:0;pointer-events:none;}
.rp-toggle span{
  display:block;
  padding:0.5rem 1rem;
  border-radius:999px;
  font-family:'Josefin Sans',sans-serif;
  font-size:0.6rem;
  letter-spacing:0.14em;
  text-transform:uppercase;
  color:#7D1D30;
  transition:background .2s,color .2s;
}
.rp-toggle input[value="Yes"]:checked + span{background:#7D1D30;color:#FDF6EE;}
.rp-toggle input[value="No"]:checked + span{background:#8A5560;color:#FDF6EE;}
.rp-toggle input:focus-visible + span{outline:2px solid #C9A84C;outline-offset:1px;}
.rp-toggle input:disabled + span{opacity:.5;cursor:not-allowed;}
.rp-label{
  display:block;
  margin:1.6rem 0 0.5rem;
  font-family:'Josefin Sans',sans-serif;
  font-size:0.62rem;
  letter-spacing:0.22em;
  text-transform:uppercase;
  color:#9B2335;
}
.rsvp-panel textarea{
  width:100%;
  padding:0.9rem 1.1rem;
  background:#FDF6EE;
  border:1px solid #E8B4BB;
  border-radius:14px;
  outline:none;
  resize:vertical;
  font-family:'Cormorant Garamond',serif;
  font-size:1.15rem;
  color:#3D0C11;
}
.rsvp-panel textarea:focus{border-color:#C9A84C;box-shadow:0 0 0 3px rgba(201,168,76,0.2);}
.rp-closed{margin-top:1rem;text-align:center;font-family:'Cormorant Garamond',serif;font-size:1.1rem;color:#B3261E;}
.rp-again{
  display:block;
  margin:1.4rem auto 0;
  background:none;
  border:none;
  cursor:pointer;
  font-family:'Cormorant Garamond',serif;
  font-style:italic;
  font-size:1.1rem;
  color:#7D1D30;
  text-decoration:underline;
}
.rsvp-panel .rsvp-wrap{margin-top:1.6rem;}
.rp-done{width:100%;max-width:480px;margin:2rem auto 0;padding:0 1.2rem;text-align:center;}
.rp-done-title{font-family:'Pinyon Script',cursive;font-size:clamp(2.8rem,10vw,4rem);color:#7D1D30;line-height:1.1;}
.rp-done p:not(.rp-done-title){font-family:'Cormorant Garamond',serif;font-size:1.2rem;line-height:1.7;color:#5C1523;margin-top:0.6rem;}
.rp-done .rp-done-small{font-style:italic;font-size:1.05rem;color:#9A6B75;}
@media(max-width:420px){.rp-toggle{width:100%;}.rp-toggle label{flex:1;}.rp-toggle span{text-align:center;padding:0.6rem 0.4rem;}}

    #rsvp .sec-title{
  font-family:'Cormorant Garamond',serif;
  font-style:italic;
  font-weight:400;
  font-size:clamp(2rem,5vw,3.2rem);
  color:#7D1D30;
  text-align:center;
  margin-bottom:0.5rem;
}

    /* ── GIFT NOTE (centered) ── */
.gift-body{
  max-width:560px;
  margin:0 auto;
  padding:0 1.2rem;
  text-align:center;
}
.gift-body p{
  font-family:'Cormorant Garamond',serif;
  font-style:italic;
  font-size:clamp(1.05rem, 3.4vw, 1.3rem);
  color:#5C1523;
  line-height:1.8;
  margin:0 0 1rem;
}
.gift-body strong{
  font-style:normal;
  font-weight:600;
  color:#7D1D30;
}
</style>
</head>
<body>
<audio id="bgm" src="music/palagi.mp3" loop preload="auto"></audio>
<!-- ════════════════════════════════════
     PAGE 1: WEDDING WEBSITE
═════════════════════════════════════ -->
<div id="page-home" class="page active">

  <!-- ═══ ANIMATED BACKGROUND CANVAS ═══ -->
  <div class="bg-canvas" aria-hidden="true">
    <svg width="100%" height="100%" viewBox="0 0 1440 900" preserveAspectRatio="xMidYMid slice" xmlns="http://www.w3.org/2000/svg">
      <!-- Soft wave 1 -->
      <g class="wave1">
        <path d="M-100,600 C200,520 400,680 700,600 C1000,520 1200,650 1540,580 L1540,900 L-100,900 Z" fill="#E8B4BB" opacity="0.06"/>
      </g>
      <!-- Soft wave 2 -->
      <g class="wave2">
        <path d="M-100,700 C300,630 500,750 800,690 C1100,630 1300,720 1540,680 L1540,900 L-100,900 Z" fill="#C9A84C" opacity="0.04"/>
      </g>
      <!-- Soft wave 3 -->
      <g class="wave3">
        <path d="M-100,550 C150,490 350,590 650,540 C950,490 1150,580 1540,520 L1540,900 L-100,900 Z" fill="#F5E6E8" opacity="0.08"/>
      </g>

      <!-- Top-left large bloom (sampaguita inspired) -->
      <g class="bloom1" style="transform-origin:160px 160px">
        <g transform="translate(160,160)" opacity="0.06" fill="#C9A84C">
          <ellipse rx="8" ry="55" transform="rotate(0)"/>
          <ellipse rx="8" ry="55" transform="rotate(30)"/>
          <ellipse rx="8" ry="55" transform="rotate(60)"/>
          <ellipse rx="8" ry="55" transform="rotate(90)"/>
          <ellipse rx="8" ry="55" transform="rotate(120)"/>
          <ellipse rx="8" ry="55" transform="rotate(150)"/>
          <circle r="18" fill="#E8D5A3"/>
        </g>
      </g>

      <!-- Bottom-right bloom -->
      <g class="bloom2" style="transform-origin:1300px 750px">
        <g transform="translate(1300,750)" opacity="0.05" fill="#7D1D30">
          <ellipse rx="6" ry="48" transform="rotate(0)"/>
          <ellipse rx="6" ry="48" transform="rotate(36)"/>
          <ellipse rx="6" ry="48" transform="rotate(72)"/>
          <ellipse rx="6" ry="48" transform="rotate(108)"/>
          <ellipse rx="6" ry="48" transform="rotate(144)"/>
          <circle r="14"/>
        </g>
      </g>

      <!-- Center-right medium bloom -->
      <g class="bloom3" style="transform-origin:1200px 350px">
        <g transform="translate(1200,350)" opacity="0.04" fill="#E8B4BB">
          <ellipse rx="5" ry="40" transform="rotate(0)"/>
          <ellipse rx="5" ry="40" transform="rotate(45)"/>
          <ellipse rx="5" ry="40" transform="rotate(90)"/>
          <ellipse rx="5" ry="40" transform="rotate(135)"/>
          <circle r="12" fill="#C9A84C"/>
        </g>
      </g>

      <!-- Top-right small bloom -->
      <g class="bloom4" style="transform-origin:1380px 120px">
        <g transform="translate(1380,120)" opacity="0.05" fill="#C9A84C">
          <ellipse rx="5" ry="38" transform="rotate(0)"/>
          <ellipse rx="5" ry="38" transform="rotate(30)"/>
          <ellipse rx="5" ry="38" transform="rotate(60)"/>
          <ellipse rx="5" ry="38" transform="rotate(90)"/>
          <ellipse rx="5" ry="38" transform="rotate(120)"/>
          <ellipse rx="5" ry="38" transform="rotate(150)"/>
          <circle r="11" fill="#E8D5A3"/>
        </g>
      </g>

      <!-- Bottom-left small bloom -->
      <g class="bloom5" style="transform-origin:100px 820px">
        <g transform="translate(100,820)" opacity="0.05" fill="#9B2335">
          <ellipse rx="5" ry="35" transform="rotate(0)"/>
          <ellipse rx="5" ry="35" transform="rotate(45)"/>
          <ellipse rx="5" ry="35" transform="rotate(90)"/>
          <ellipse rx="5" ry="35" transform="rotate(135)"/>
          <circle r="10" fill="#E8B4BB"/>
        </g>
      </g>
    </svg>

    <!-- Glowing orbs -->
    <div class="orb orb1" style="width:500px;height:500px;background:radial-gradient(circle,#F5E6E8 0%,transparent 70%);top:-100px;left:-150px;opacity:0.5;"></div>
    <div class="orb orb2" style="width:400px;height:400px;background:radial-gradient(circle,#E8D5A344 0%,transparent 70%);bottom:100px;right:-100px;opacity:0.6;"></div>
    <div class="orb orb3" style="width:300px;height:300px;background:radial-gradient(circle,#E8B4BB33 0%,transparent 70%);top:40%;left:60%;opacity:0.5;"></div>
  </div>

  <nav class="nav">
    <a class="nav-brand" href="#banner">Jheroll &amp; Jennie</a>
    <ul class="nav-links" style="justify-content:center;">
      <li><a href="#our-story">our story</a></li>
      <li><a href="#location">location</a></li>
      <li><a href="#outfit">outfit</a></li>
      <li><a href="#playlist">playlist</a></li>
      <li><a href="#prenup">prenup</a></li>
      <li><a href="#entourage">entourage</a></li>
      <li><a href="#rsvp">RSVP</a></li>
    </ul>
    <div></div>
  </nav>



  <!-- Reopen pill -->
  <button class="vinyl-reopen" id="vinyl-reopen" onclick="openVinyl()">
    <svg width="12" height="12" viewBox="0 0 12 12"><circle cx="6" cy="6" r="5" stroke="#C9A84C" stroke-width="1" fill="none"/><circle cx="6" cy="6" r="2" fill="#C9A84C"/></svg>
    ♪ Music
  </button>

<!-- PHOTO BANNER -->
<section class="photo-banner" id="banner">
  <img class="pb-img" src="images/banner.jpeg" alt="Jheroll and Jennie">
  <div class="pb-shade"></div>

  <img class="pb-logo" src="images/logo.png" alt="Jheroll &amp; Jennie logo">



  <div class="pb-bottom">
<div class="pb-names-row">
  <div class="pb-person">
    <p class="pb-first">Jheroll</p>
    <p class="pb-last">Porras</p>
  </div>

  <span class="pb-amp">&amp;</span>

  <div class="pb-person">
    <p class="pb-first">Jennie</p>
    <p class="pb-last">Rodriguez</p>
  </div>
</div>
    <p class="pb-ornament"> cordially invite you to celebrate their wedding </p>
    <p class="pb-date">Saturday · February 6, 2027 · 09:00 in the morning</p>
    <p class="pb-tagline">Two hearts, one forever</p>
   
  </div>
</section>



  <!-- OUR STORY -->
  <section id="our-story" style="background:#F0E8E0;">
    <div class="sec-bg" aria-hidden="true">
      <svg width="100%" height="100%" viewBox="0 0 900 600" preserveAspectRatio="xMidYMid slice">
        <g class="bloom2" style="transform-origin:820px 80px">
          <g transform="translate(820,80)" opacity="0.06" fill="#C9A84C">
            <ellipse rx="6" ry="50" transform="rotate(0)"/><ellipse rx="6" ry="50" transform="rotate(36)"/><ellipse rx="6" ry="50" transform="rotate(72)"/><ellipse rx="6" ry="50" transform="rotate(108)"/><ellipse rx="6" ry="50" transform="rotate(144)"/><circle r="15" fill="#E8D5A3"/>
          </g>
        </g>
        <g class="bloom3" style="transform-origin:50px 500px">
          <g transform="translate(50,500)" opacity="0.05" fill="#E8B4BB">
            <ellipse rx="5" ry="40" transform="rotate(0)"/><ellipse rx="5" ry="40" transform="rotate(45)"/><ellipse rx="5" ry="40" transform="rotate(90)"/><ellipse rx="5" ry="40" transform="rotate(135)"/><circle r="12" fill="#C9A84C"/>
          </g>
        </g>
        <path class="wave1" d="M0,400 C200,350 400,430 600,390 C750,360 850,410 900,380 L900,600 L0,600 Z" fill="#E8B4BB" opacity="0.04"/>
      </svg>
    </div>
    <div class="inner">
      <p class="sec-label reveal"> Our Love Story </p>
      <h2 class="sec-title reveal">How It All Began</h2>
      <div class="sec-div reveal"></div>
      <div class="timeline stagger">

        <!-- 1. How we met -->
      <div class="t-item">
      <div class="t-content">
        <p class="t-year">How we met</p>
        <p class="t-text">Our story began when a coworker played matchmaker. <strong>She saw it before we did</strong>, and now she's our Ninang.</p>
      </div>
      <div class="t-center"><div class="t-dot"></div></div>
      <div class="t-photo">
        <div class="t-photo-frame zoomable">
          <img src="images/journey1.jpeg" alt="How we met" loading="lazy">
          <div class="corner tl"></div><div class="corner tr"></div><div class="corner bl"></div><div class="corner br"></div>
          <span class="zoom-hint">⤢</span>
        </div>
      </div>
    </div>

   <!-- 1. First date -->
<div class="t-item">
  <div class="t-content">
    <p class="t-year">First date</p>
    <p class="t-text">Nervous smiles and the best conversation of our lives. We watched <strong>Evil Dead Rise</strong>, and somewhere between the scares, we discovered we were meant to find each other.</p>
  </div>
  <div class="t-center"><div class="t-dot"></div></div>
  <div class="t-photo">
    <div class="t-photo-frame zoomable">
      <img src="images/journey2.jpeg" alt="First date" loading="lazy">
      <div class="corner tl"></div><div class="corner tr"></div><div class="corner bl"></div><div class="corner br"></div>
      <span class="zoom-hint">⤢</span>
    </div>
  </div>
</div>



<!-- 3. Falling in love -->
<div class="t-item">
  <div class="t-content">
    <p class="t-year">Falling in love</p>
   <p class="t-text">Through every season &mdash; the laughter, the adventures, the quiet evenings &mdash; we built something beautiful. <strong>Every moment made us more certain.</strong></p>
  </div>
  <div class="t-center"><div class="t-dot"></div></div>
  <div class="t-photo">
    <div class="t-photo-frame zoomable">
      <img src="images/journey3.jpeg" alt="Falling in love" loading="lazy">
      <div class="corner tl"></div><div class="corner tr"></div><div class="corner bl"></div><div class="corner br"></div>
      <span class="zoom-hint">⤢</span>
    </div>
  </div>
</div>

<!-- 4. The proposal -->
<div class="t-item">
  <div class="t-photo">
    <div class="t-photo-frame zoomable">
      <img src="images/journey4.jpeg" alt="The proposal" loading="lazy">
      <div class="corner tl"></div><div class="corner tr"></div><div class="corner bl"></div><div class="corner br"></div>
      <span class="zoom-hint">⤢</span>
    </div>
  </div>
  <div class="t-center"><div class="t-dot"></div></div>
  <div class="t-content">
    <p class="t-year">The proposal</p>
    <p class="t-text">On our 2nd anniversary, with a heart full of love and a ring that had been waiting for the perfect moment, <strong>the answer was always yes</strong>.</p>
  </div>
</div>

<!-- 5. Forever begins -->
<div class="t-item">
  <div class="t-content">
    <p class="t-year">Forever begins</p>
    <p class="t-text">And now, surrounded by everyone we love most, we begin our greatest adventure together. <strong>Two hearts, one story.</strong></p>
  </div>
  <div class="t-center"><div class="t-dot"></div></div>
  <div class="t-photo">
    <div class="t-photo-frame zoomable">
      <img src="images/journey5.jpeg" alt="Our wedding" loading="lazy">
      <div class="corner tl"></div><div class="corner tr"></div><div class="corner bl"></div><div class="corner br"></div>
      <span class="zoom-hint">⤢</span>
    </div>
  </div>
</div>

      </div>
    </div>
  </section>

  <!-- LOCATION -->
  <section id="location" style="background:#7D1D30;">
    <div class="sec-bg" aria-hidden="true">
      <svg width="100%" height="100%" viewBox="0 0 900 500" preserveAspectRatio="xMidYMid slice">
        <g class="bloom1" style="transform-origin:80px 100px">
          <g transform="translate(80,100)" opacity="0.07" fill="#7D1D30">
            <ellipse rx="6" ry="45" transform="rotate(0)"/><ellipse rx="6" ry="45" transform="rotate(30)"/><ellipse rx="6" ry="45" transform="rotate(60)"/><ellipse rx="6" ry="45" transform="rotate(90)"/><ellipse rx="6" ry="45" transform="rotate(120)"/><ellipse rx="6" ry="45" transform="rotate(150)"/><circle r="14" fill="#E8B4BB"/>
          </g>
        </g>
        <g class="bloom4" style="transform-origin:860px 400px">
          <g transform="translate(860,400)" opacity="0.06" fill="#C9A84C">
            <ellipse rx="5" ry="38" transform="rotate(0)"/><ellipse rx="5" ry="38" transform="rotate(45)"/><ellipse rx="5" ry="38" transform="rotate(90)"/><ellipse rx="5" ry="38" transform="rotate(135)"/><circle r="12" fill="#E8D5A3"/>
          </g>
        </g>
        <path class="wave2" d="M0,350 C150,300 350,380 600,330 C780,295 870,350 900,320 L900,500 L0,500 Z" fill="#C9A84C" opacity="0.04"/>
      </svg>
    </div>
    <div class="inner">
      <p class="sec-label reveal" style="color:#C9A84C;"> Venue </p>
      <h2 class="sec-title reveal" style="color:#E8D5A3;">Where We Say I Do</h2>
      <div class="sec-div reveal"></div>
      <div class="venue-hero reveal">
        <img class="venue-hero-img"
          src="https://www.preselling.com.ph/wp-content/uploads/2020/09/Chateaux-De-Paris-Clubhouse-800x417.jpg"
          alt="Chateaux De Paris"/>
        <div class="venue-hero-overlay"></div>
        <div class="venue-hero-content">
          <div class="venue-divider">
            <div class="venue-divider-line"></div>
            <span class="venue-divider-diamond"></span>
            <div class="venue-divider-line"></div>
          </div>
          <p class="venue-name">Chateaux De Paris</p>
          <p class="venue-addr">South Forbes Golf City, South Blvd, Brgy. Inchikan, Silang, Cavite 4118</p>

          <a href="https://maps.google.com/?q=Chateaux+De+Paris+South+Forbes+Golf+City+Silang+Cavite" class="map-link" target="_blank">Open in Maps ↗</a>
        </div>
      </div>
      <p class="venue-note" style="color:#E8D5A3;text-align:center;font-family:'Cormorant Garamond',serif;font-style:italic;font-size:1.05rem;margin-top:1rem;">
        Ceremony & Reception at <strong>Chateaux De Paris</strong> · Silang, Cavite
      </p>
    </div>
  </section>

  <!-- OUTFIT -->
  <section id="outfit" style="background:#FDF6EE;">
    <div class="sec-bg" aria-hidden="true">
      <svg width="100%" height="100%" viewBox="0 0 900 600" preserveAspectRatio="xMidYMid slice">
        <g class="bloom5" style="transform-origin:870px 100px">
          <g transform="translate(870,100)" opacity="0.06" fill="#9B2335">
            <ellipse rx="6" ry="50" transform="rotate(0)"/><ellipse rx="6" ry="50" transform="rotate(36)"/><ellipse rx="6" ry="50" transform="rotate(72)"/><ellipse rx="6" ry="50" transform="rotate(108)"/><ellipse rx="6" ry="50" transform="rotate(144)"/><circle r="15" fill="#E8B4BB"/>
          </g>
        </g>
        <g class="bloom2" style="transform-origin:60px 520px">
          <g transform="translate(60,520)" opacity="0.05" fill="#C9A84C">
            <ellipse rx="5" ry="42" transform="rotate(0)"/><ellipse rx="5" ry="42" transform="rotate(30)"/><ellipse rx="5" ry="42" transform="rotate(60)"/><ellipse rx="5" ry="42" transform="rotate(90)"/><ellipse rx="5" ry="42" transform="rotate(120)"/><ellipse rx="5" ry="42" transform="rotate(150)"/><circle r="13" fill="#E8D5A3"/>
          </g>
        </g>
        <path class="wave3" d="M0,450 C250,400 450,480 700,430 C840,400 890,450 900,420 L900,600 L0,600 Z" fill="#E8B4BB" opacity="0.05"/>
      </svg>
    </div>
    <div class="inner">
      <p class="sec-label reveal"> Dress Guide </p>
      <h2 class="sec-title reveal">Outfit Color Palette</h2>
      <div class="sec-div reveal"></div>
      <div class="outfit-grid stagger">
        <div class="outfit-card">
          <div class="swatch" style="background:#F8F4EC"></div>
          <p class="o-color">Ivory</p>
        </div>
        <div class="outfit-card">
          <div class="swatch" style="background:#A08060"></div>
          <p class="o-color">Taupe</p>
        </div>
        <div class="outfit-card">
          <div class="swatch" style="background:#E8D5B0"></div>
         <p class="o-color">Soft Champagne</p>
        </div>
         <div class="outfit-card">
          <div class="swatch" style="background:#C4908A"></div>
         <p class="o-color">Dusty Rose</p>
        </div>
        <div class="outfit-card">
          <div class="swatch" style="background:#7D1D30"></div>
         <p class="o-color">Burgundy</p>
        </div>
      </div>

    <figure class="ent-photo">
      <img src="images/ninong_ninang.png" alt="Principal Sponsors outfit" loading="lazy">
      <figcaption>
        <span class="outfit-title">Principal Sponsors Outfit</span>
        <span class="outfit-line"><strong>Ninong:</strong> Barong Tagalog &amp; slacks</span>
        <span class="outfit-line"><strong>Ninang:</strong> Filipiniana gown</span>
      </figcaption>
    </figure>

      

<figure class="guest-photo">
  <span class="outfit-title">Guests Outfit</span>
  <img src="images/guest.png" alt="Guest outfit inspiration" loading="lazy">
  <figcaption>
    <span class="guest-msg">We kindly request our guests to wear formal attire.</span>
    <span class="guest-hues">In hues of the Outfit Color Palette mentioned above.</span>
  </figcaption>
</figure>


      
    </div>
  </section>

  <!-- PLAYLIST -->
  <section id="playlist" style="background:#F0E8E0;">
    <div class="sec-bg" aria-hidden="true">
      <svg width="100%" height="100%" viewBox="0 0 900 600" preserveAspectRatio="xMidYMid slice">
        <g class="bloom3" style="transform-origin:120px 80px">
          <g transform="translate(120,80)" opacity="0.07" fill="#7D1D30">
            <ellipse rx="7" ry="52" transform="rotate(0)"/><ellipse rx="7" ry="52" transform="rotate(45)"/><ellipse rx="7" ry="52" transform="rotate(90)"/><ellipse rx="7" ry="52" transform="rotate(135)"/><circle r="16" fill="#E8B4BB"/>
          </g>
        </g>
        <g class="bloom1" style="transform-origin:820px 520px">
          <g transform="translate(820,520)" opacity="0.05" fill="#C9A84C">
            <ellipse rx="5" ry="40" transform="rotate(0)"/><ellipse rx="5" ry="40" transform="rotate(36)"/><ellipse rx="5" ry="40" transform="rotate(72)"/><ellipse rx="5" ry="40" transform="rotate(108)"/><ellipse rx="5" ry="40" transform="rotate(144)"/><circle r="13" fill="#E8D5A3"/>
          </g>
        </g>
        <path class="wave1" d="M0,500 C200,450 400,520 650,480 C800,455 880,500 900,475 L900,600 L0,600 Z" fill="#C9A84C" opacity="0.04"/>
      </svg>
    </div>
    <div class="inner">
      <p class="sec-label reveal"> Music </p>
      <h2 class="sec-title reveal">Our Wedding Playlist</h2>
      <div class="sec-div reveal"></div>
      <div class="reveal" style="display:flex;flex-direction:column;align-items:center;gap:1.5rem;">
        <p style="font-family:'Cormorant Garamond',serif;font-style:italic;font-size:1.15rem;color:#7D1D30;text-align:center;">Every song we love — from the aisle to the dance floor</p>

           <div style="display:flex;gap:1rem;justify-content:center;flex-wrap:wrap;">
            <a href="https://open.spotify.com/playlist/0K40OZ2gjdClqdVwmrFVtI" target="_blank"
              style="display:inline-flex;align-items:center;gap:0.5rem;background:#1DB954;color:#fff;font-family:'Josefin Sans',sans-serif;font-size:0.62rem;letter-spacing:0.15em;text-transform:uppercase;padding:0.75rem 1.5rem;border-radius:50px;text-decoration:none;transition:opacity 0.2s;">
              <svg width="14" height="14" viewBox="0 0 24 24" fill="white"><path d="M12 0C5.4 0 0 5.4 0 12s5.4 12 12 12 12-5.4 12-12S18.66 0 12 0zm5.521 17.34c-.24.359-.66.48-1.021.24-2.82-1.74-6.36-2.101-10.561-1.141-.418.122-.779-.179-.899-.539-.12-.421.18-.78.54-.9 4.56-1.021 8.52-.6 11.64 1.32.42.18.479.659.301 1.02zm1.44-3.3c-.301.42-.841.6-1.262.3-3.239-1.98-8.159-2.58-11.939-1.38-.479.12-1.02-.12-1.14-.6-.12-.48.12-1.021.6-1.141C9.6 9.9 15 10.561 18.72 12.84c.361.181.54.78.241 1.2zm.12-3.36C15.24 8.4 8.82 8.16 5.16 9.301c-.6.179-1.2-.181-1.38-.721-.18-.601.18-1.2.72-1.381 4.26-1.26 11.28-1.02 15.721 1.621.539.3.719 1.02.419 1.56-.299.421-1.02.599-1.559.3z"/></svg>
              Open in Spotify
            </a>
          </div>

        <!-- Now Playing Card -->
        <div style="background:#FDF6EE;border:0.5px solid #E8B4BB;border-radius:4px;padding:2rem 2.5rem;max-width:480px;width:100%;text-align:center;box-shadow:0 4px 24px rgba(125,29,48,0.08);">
          <!-- Animated music bars -->
          <div style="display:flex;align-items:flex-end;justify-content:center;gap:4px;height:36px;margin-bottom:1.25rem;">
            <div style="width:4px;background:#C9A84C;border-radius:2px;animation:barBounce 0.8s ease-in-out infinite;"></div>
            <div style="width:4px;background:#C9A84C;border-radius:2px;animation:barBounce 0.8s ease-in-out infinite;animation-delay:0.15s;"></div>
            <div style="width:4px;background:#7D1D30;border-radius:2px;animation:barBounce 0.8s ease-in-out infinite;animation-delay:0.3s;"></div>
            <div style="width:4px;background:#C9A84C;border-radius:2px;animation:barBounce 0.8s ease-in-out infinite;animation-delay:0.45s;"></div>
            <div style="width:4px;background:#7D1D30;border-radius:2px;animation:barBounce 0.8s ease-in-out infinite;animation-delay:0.6s;"></div>
            <div style="width:4px;background:#C9A84C;border-radius:2px;animation:barBounce 0.8s ease-in-out infinite;animation-delay:0.15s;"></div>
            <div style="width:4px;background:#7D1D30;border-radius:2px;animation:barBounce 0.8s ease-in-out infinite;animation-delay:0.45s;"></div>
          </div>
          <p style="font-family:'Josefin Sans',sans-serif;font-size:0.55rem;letter-spacing:0.25em;text-transform:uppercase;color:#C9A84C;margin-bottom:0.4rem;">Now Playing in Background</p>
          <p style="font-family:'Pinyon Script',cursive;font-size:2rem;color:#7D1D30;margin-bottom:0.3rem;letter-spacing:0.02em;">Palagi - TJxKZ Version </p>
          <p style="font-family:'Josefin Sans',sans-serif;font-size:0.6rem;letter-spacing:0.1em;color:#C4908A;margin-bottom:1.5rem;">Music playing automatically in the background</p>
       
        </div>
      </div>
    </div>
  </section>

  <!-- SPONSORS 
  <section id="sponsors" style="background:#5C1523;">
    <div class="sec-bg" aria-hidden="true">
      <svg width="100%" height="100%" viewBox="0 0 900 500" preserveAspectRatio="xMidYMid slice">
        <g class="bloom4" style="transform-origin:860px 80px">
          <g transform="translate(860,80)" opacity="0.06" fill="#9B2335">
            <ellipse rx="6" ry="46" transform="rotate(0)"/><ellipse rx="6" ry="46" transform="rotate(30)"/><ellipse rx="6" ry="46" transform="rotate(60)"/><ellipse rx="6" ry="46" transform="rotate(90)"/><ellipse rx="6" ry="46" transform="rotate(120)"/><ellipse rx="6" ry="46" transform="rotate(150)"/><circle r="14" fill="#E8B4BB"/>
          </g>
        </g>
        <g class="bloom5" style="transform-origin:80px 420px">
          <g transform="translate(80,420)" opacity="0.05" fill="#C9A84C">
            <ellipse rx="5" ry="36" transform="rotate(0)"/><ellipse rx="5" ry="36" transform="rotate(45)"/><ellipse rx="5" ry="36" transform="rotate(90)"/><ellipse rx="5" ry="36" transform="rotate(135)"/><circle r="11" fill="#E8D5A3"/>
          </g>
        </g>
        <path class="wave2" d="M0,380 C180,330 380,400 620,360 C780,330 870,380 900,350 L900,500 L0,500 Z" fill="#E8B4BB" opacity="0.05"/>
      </svg>
    </div>

  </section>-->

  <!-- PRENUP PHOTOS -->
  <section id="prenup" style="background:#3D0C11;padding:5rem 2rem;">
    <div class="inner" style="max-width:1100px;">
      <p class="sec-label" style="color:#C9A84C;"> Prenuptial Session </p>
      <h2 class="sec-title reveal" style="color:#E8D5A3;">Before Forever</h2>
      <div class="sec-div reveal"></div>
      <p style="text-align:center;font-family:'Cormorant Garamond',serif;font-size:1.1rem;color:#E8B4BB;margin-bottom:2.5rem;" class="reveal">A glimpse of our love story, captured in light.</p>

    <!-- 3 FEATURED PHOTOS -->
<div class="prenup-featured reveal">
  <div class="pf-item" onclick="openLightbox(0)">
    <img src="images/prenup1.jpeg" alt="Prenup photo 1" loading="lazy">
    <span class="zoom-hint">View Photo</span>
  </div>
  <div class="pf-item" onclick="openLightbox(1)">
    <img src="images/prenup2.jpeg" alt="Prenup photo 2" loading="lazy">
    <span class="zoom-hint">View Photo</span>
  </div>
  <div class="pf-item" onclick="openLightbox(2)">
    <img src="images/prenup3.jpeg" alt="Prenup photo 3" loading="lazy">
    <span class="zoom-hint">View Photo</span>
  </div>
</div>

<!-- ALBUM TOGGLE -->
<div class="album-toggle">
  <button class="album-toggle-btn active" onclick="toggleAlbum(this)">View Full Album</button>
  <span class="album-count" id="album-count"></span>
</div>

<!-- FULL ALBUM (9 more photos) -->
<div class="prenup-album reveal" id="prenup-album" style="display:none;">
  <div class="pa-item" onclick="openLightbox(3)"><img src="images/prenup4.jpeg" alt="Prenup photo 4" loading="lazy"><span class="zoom-hint">View</span></div>
  <div class="pa-item" onclick="openLightbox(4)"><img src="images/prenup5.jpeg" alt="Prenup photo 5" loading="lazy"><span class="zoom-hint">View</span></div>
  <div class="pa-item" onclick="openLightbox(5)"><img src="images/prenup6.jpeg" alt="Prenup photo 6" loading="lazy"><span class="zoom-hint">View</span></div>
  <div class="pa-item" onclick="openLightbox(6)"><img src="images/prenup7.jpeg" alt="Prenup photo 7" loading="lazy"><span class="zoom-hint">View</span></div>
  <div class="pa-item" onclick="openLightbox(7)"><img src="images/prenup8.jpeg" alt="Prenup photo 8" loading="lazy"><span class="zoom-hint">View</span></div>
  <div class="pa-item" onclick="openLightbox(8)"><img src="images/prenup9.jpeg" alt="Prenup photo 9" loading="lazy"><span class="zoom-hint">View</span></div>
  <div class="pa-item" onclick="openLightbox(9)"><img src="images/prenup10.jpeg" alt="Prenup photo 10" loading="lazy"><span class="zoom-hint">View</span></div>
  <div class="pa-item" onclick="openLightbox(10)"><img src="images/prenup11.jpeg" alt="Prenup photo 11" loading="lazy"><span class="zoom-hint">View</span></div>
  <div class="pa-item" onclick="openLightbox(11)"><img src="images/prenup12.jpeg" alt="Prenup photo 12" loading="lazy"><span class="zoom-hint">View</span></div>
</div>

     
    </div>
  </section>

  <!-- LIGHTBOX -->
  <div class="lightbox" id="lightbox" onclick="closeLightboxOutside(event)">
    <button class="lightbox-close" onclick="closeLightbox()">✕</button>
    <button class="lightbox-prev" onclick="shiftLightbox(-1)">‹</button>
    <img class="lightbox-img" id="lightbox-img" src="" alt="Prenup photo"/>
    <button class="lightbox-next" onclick="shiftLightbox(1)">›</button>
    <div class="lightbox-counter" id="lightbox-counter"></div>
  </div>

   <!-- ENTOURAGE -->
  <section id="entourage" style="background:#FDF6EE;">
    <div class="sec-bg" aria-hidden="true">
      <svg width="100%" height="100%" viewBox="0 0 900 700" preserveAspectRatio="xMidYMid slice">
        <g class="bloom1" style="transform-origin:150px 150px">
          <g transform="translate(150,150)" opacity="0.07" fill="#7D1D30">
            <ellipse rx="7" ry="55" transform="rotate(0)"/><ellipse rx="7" ry="55" transform="rotate(36)"/><ellipse rx="7" ry="55" transform="rotate(72)"/><ellipse rx="7" ry="55" transform="rotate(108)"/><ellipse rx="7" ry="55" transform="rotate(144)"/><circle r="17" fill="#E8B4BB"/>
          </g>
        </g>
        <g class="bloom2" style="transform-origin:800px 600px">
          <g transform="translate(800,600)" opacity="0.06" fill="#C9A84C">
            <ellipse rx="6" ry="48" transform="rotate(0)"/><ellipse rx="6" ry="48" transform="rotate(30)"/><ellipse rx="6" ry="48" transform="rotate(60)"/><ellipse rx="6" ry="48" transform="rotate(90)"/><ellipse rx="6" ry="48" transform="rotate(120)"/><ellipse rx="6" ry="48" transform="rotate(150)"/><circle r="15" fill="#E8D5A3"/>
          </g>
        </g>
        <g class="bloom3" style="transform-origin:500px 100px">
          <g transform="translate(500,100)" opacity="0.04" fill="#E8B4BB">
            <ellipse rx="5" ry="38" transform="rotate(0)"/><ellipse rx="5" ry="38" transform="rotate(45)"/><ellipse rx="5" ry="38" transform="rotate(90)"/><ellipse rx="5" ry="38" transform="rotate(135)"/><circle r="12" fill="#C9A84C"/>
          </g>
        </g>
        <path class="wave3" d="M0,550 C200,500 400,570 650,530 C800,505 880,550 900,520 L900,700 L0,700 Z" fill="#C9A84C" opacity="0.04"/>
        <path class="wave1" d="M0,620 C250,575 450,640 700,600 C840,575 890,620 900,595 L900,700 L0,700 Z" fill="#E8B4BB" opacity="0.05"/>
      </svg>
    </div>

    <div class="inner">
      <p class="sec-label reveal"> The Wedding Party </p>
      <h2 class="sec-title reveal">Our Beloved Entourage</h2>
      <div class="sec-div reveal"></div>

      <div class="stagger">

        <div class="ent-group">
          <p class="ent-gtitle">— Parents of the Groom —</p>
          <div class="ent-names"><span class="ent-name">Robert &amp; Lelita Porras</span></div>
        </div>

        <div class="ent-group">
          <p class="ent-gtitle">— Parents of the Bride —</p>
          <div class="ent-names"><span class="ent-name">Romeo &amp; Josephine Rodriguez</span></div>
        </div>

        <div class="ent-group">
          <p class="ent-gtitle">— Principal Sponsors —</p>
          <div class="ent-names"><span class="ent-name">Angelito &amp; Susan Rodriguez</span></div>
          <div class="ent-names"><span class="ent-name">Joe &amp; Susan Caruana</span></div>
          <div class="ent-names"><span class="ent-name">Cerilio &amp; Loida Dela Luya</span></div>
          <div class="ent-names"><span class="ent-name">Elmer &amp; Merilin Rodriguez</span></div>
          <div class="ent-names"><span class="ent-name">George &amp; Lorenie Maullon</span></div>
          <div class="ent-names"><span class="ent-name">Mario Carlo Dizon</span></div>
          <div class="ent-names"><span class="ent-name">Maribeth Ramos</span></div>
          <div class="ent-names"><span class="ent-name">Ryan Reyes</span></div>
          <div class="ent-names"><span class="ent-name">Annabelle Garcia</span></div>
          <div class="ent-names"><span class="ent-name">Amelita Legaspi</span></div>
        </div>

        <div class="ent-group">
          <p class="ent-gtitle">— Best Man —</p>
          <div class="ent-names"><span class="ent-name">Leo Sagitarios</span></div>
        </div>

        <div class="ent-group">
          <p class="ent-gtitle">— Maid of Honor —</p>
          <div class="ent-names"><span class="ent-name">Anne Bernadette Laudato</span></div>
        </div>

        <div class="ent-group">
          <p class="ent-gtitle">— Groomsmen —</p>
          <div class="ent-names">
            <span class="ent-name">Jervin Tolentino</span><span class="ent-name">·</span><span class="ent-name">Enrie John Edem</span>
          </div>
        </div>

        <div class="ent-group">
          <p class="ent-gtitle">— Bridesmaids —</p>
          <div class="ent-names">
            <span class="ent-name">Lianne Kristel Alpis</span><span class="ent-name">·</span><span class="ent-name">Nikee Ibarrientos</span>
          </div>
        </div>

        <div class="ent-group">
          <p class="ent-gtitle">— Secondary Sponsors —</p>

          <p class="ent-sub">Candle</p>
          <div class="ent-names">
            <span class="ent-name">Zyrone Topacio</span><span class="ent-name">·</span><span class="ent-name">Janine Demabildo</span>
          </div>

          <p class="ent-sub">Veil</p>
          <div class="ent-names">
            <span class="ent-name">John Philip Porras</span><span class="ent-name">·</span><span class="ent-name">Sarah Jane Porras</span>
          </div>

          <p class="ent-sub">Cord</p>
          <div class="ent-names">
            <span class="ent-name">Jeric Ramos</span><span class="ent-name">·</span><span class="ent-name">Jealou Rodriguez</span>
          </div>
        </div>

        <div class="ent-group">
          <p class="ent-gtitle">— Ring Bearer —</p>
          <div class="ent-names"><span class="ent-name">Ezekiel Gomez</span></div>
        </div>

        <div class="ent-group">
          <p class="ent-gtitle">— Bible Bearer —</p>
          <div class="ent-names"><span class="ent-name">Zeth Hunter Dela Luya</span></div>
        </div>

        <div class="ent-group">
          <p class="ent-gtitle">— Coin Bearer —</p>
          <div class="ent-names"><span class="ent-name">Ralph Caleb Pangilinan</span></div>
        </div>

        <div class="ent-group">
          <p class="ent-gtitle">— Flower Girls —</p>
          <div class="ent-names">
            <span class="ent-name">Kamila Pauleen Buhain</span><span class="ent-name">·</span><span class="ent-name">Bria Porcioncula</span>
          </div>
        </div>

      </div><!-- /stagger -->
    </div><!-- /inner -->
  </section>

  

     <!-- RSVP -->
  <section id="rsvp" style="background:#F0E8E0;">
    <div class="sec-bg" aria-hidden="true">
      <svg width="100%" height="100%" viewBox="0 0 900 600" preserveAspectRatio="xMidYMid slice">
        <g class="bloom3" style="transform-origin:120px 80px">
          <g transform="translate(120,80)" opacity="0.07" fill="#7D1D30">
            <ellipse rx="7" ry="52" transform="rotate(0)"/><ellipse rx="7" ry="52" transform="rotate(45)"/><ellipse rx="7" ry="52" transform="rotate(90)"/><ellipse rx="7" ry="52" transform="rotate(135)"/><circle r="16" fill="#E8B4BB"/>
          </g>
        </g>
        <g class="bloom1" style="transform-origin:820px 520px">
          <g transform="translate(820,520)" opacity="0.05" fill="#C9A84C">
            <ellipse rx="5" ry="40" transform="rotate(0)"/><ellipse rx="5" ry="40" transform="rotate(36)"/><ellipse rx="5" ry="40" transform="rotate(72)"/><ellipse rx="5" ry="40" transform="rotate(108)"/><ellipse rx="5" ry="40" transform="rotate(144)"/><circle r="13" fill="#E8D5A3"/>
          </g>
        </g>
        <path class="wave1" d="M0,500 C200,450 400,520 650,480 C800,455 880,500 900,475 L900,600 L0,600 Z" fill="#C9A84C" opacity="0.04"/>
      </svg>
    </div>

    <div class="inner">
  <div class="rsvp-logo">
    <img src="images/logo.png" alt="Jheroll &amp; Jennie logo" loading="lazy">
  </div>

<h2 class="sec-title reveal">RSVP</h2>

      <div class="rsvp-note">
        <p class="rsvp-seats">We have reserved <span class="seat-blank">&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;</span> seat(s) in your honor.</p>

        <p>Kindly confirm your attendance on or before <strong>December 13, 2026</strong>, through the RSVP section of our wedding website.</p>

        <p>Your response will help us make the necessary arrangements for our special day.</p>

        <p class="rsvp-warning">Please note: Guests who do not respond by the RSVP deadline will be considered as not attending.</p>

        <p class="rsvp-thanks">Thank you, and we look forward to celebrating with you!</p>
      </div>

<!-- SEARCH BOX -->
<form class="rsvp-search" id="rsvp-search" action="#" autocomplete="off" novalidate>
    <p class="rf-label" style="text-align:center;">Type your name to find your invitation</p>
  <div class="rf-row rf-live">
    <input id="rf-input" name="q" type="text" maxlength="60"
           role="combobox" aria-expanded="false" aria-controls="rf-list" aria-autocomplete="list"
           aria-label="Your name" autocapitalize="words" spellcheck="false"
           placeholder="Type your name here">
    <ul class="rf-list" id="rf-list" role="listbox" hidden></ul>
  </div>
  <p class="rf-hint" id="rf-hint" role="alert" hidden></p>
</form>



<!-- YOUR GROUP: appears after a name is chosen -->
<div class="rsvp-panel" id="rsvp-panel" hidden>
  <p class="rp-title">Will You Be Joining Us?</p>
  <p class="rp-sub">Choose for each person on your invitation.</p>
  <div id="rp-members"></div>

  <label class="rp-label" for="rp-message">Message to the couple (optional)</label>
  <textarea id="rp-message" rows="3" maxlength="500" placeholder="Share your wishes..."></textarea>

  <p class="rf-hint" id="rp-error" role="alert" hidden></p>
  <p class="rp-closed" id="rp-closed" hidden>The RSVP deadline has passed. Please contact the couple.</p>

  <div class="rsvp-wrap">
    <button type="button" class="rsvp-btn" id="rp-confirm">Confirm RSVP ♥</button>
  </div>
  <button type="button" class="rp-again" id="rp-again">Not you? Search again</button>
</div>

<!-- THANK YOU -->
<div class="rp-done" id="rp-done" hidden>
  <p class="rp-done-title">Thank You!</p>
  <p id="rp-done-text"></p>
  <p class="rp-done-small">You can search again to change your answer before December 13, 2026.</p>
  <button type="button" class="rp-again" id="rp-done-again">Back to search</button>
</div>


   


    </div>
  </section>


  <section id="gift-note" style="background:#FDF6EE;">
    <div class="sec-bg" aria-hidden="true">
      <svg width="100%" height="100%" viewBox="0 0 900 700" preserveAspectRatio="xMidYMid slice">
        <g class="bloom1" style="transform-origin:150px 150px">
          <g transform="translate(150,150)" opacity="0.07" fill="#7D1D30">
            <ellipse rx="7" ry="55" transform="rotate(0)"/><ellipse rx="7" ry="55" transform="rotate(36)"/><ellipse rx="7" ry="55" transform="rotate(72)"/><ellipse rx="7" ry="55" transform="rotate(108)"/><ellipse rx="7" ry="55" transform="rotate(144)"/><circle r="17" fill="#E8B4BB"/>
          </g>
        </g>
        <g class="bloom2" style="transform-origin:800px 600px">
          <g transform="translate(800,600)" opacity="0.06" fill="#C9A84C">
            <ellipse rx="6" ry="48" transform="rotate(0)"/><ellipse rx="6" ry="48" transform="rotate(30)"/><ellipse rx="6" ry="48" transform="rotate(60)"/><ellipse rx="6" ry="48" transform="rotate(90)"/><ellipse rx="6" ry="48" transform="rotate(120)"/><ellipse rx="6" ry="48" transform="rotate(150)"/><circle r="15" fill="#E8D5A3"/>
          </g>
        </g>
        <g class="bloom3" style="transform-origin:500px 100px">
          <g transform="translate(500,100)" opacity="0.04" fill="#E8B4BB">
            <ellipse rx="5" ry="38" transform="rotate(0)"/><ellipse rx="5" ry="38" transform="rotate(45)"/><ellipse rx="5" ry="38" transform="rotate(90)"/><ellipse rx="5" ry="38" transform="rotate(135)"/><circle r="12" fill="#C9A84C"/>
          </g>
        </g>
        <path class="wave3" d="M0,550 C200,500 400,570 650,530 C800,505 880,550 900,520 L900,700 L0,700 Z" fill="#C9A84C" opacity="0.04"/>
        <path class="wave1" d="M0,620 C250,575 450,640 700,600 C840,575 890,620 900,595 L900,700 L0,700 Z" fill="#E8B4BB" opacity="0.05"/>
      </svg>
    </div>

        <div class="inner">
      <p class="sec-label reveal"> Gift Note </p>
      <div class="sec-div reveal"></div>

      <div class="gift-body stagger">
        <p>Your presence on our special day is already <strong>the greatest gift</strong>.</p>
        <p>If you'd like to give us something, we would truly appreciate a cash gift to help us start our next chapter together.</p>
      </div><!-- /stagger -->
    </div><!-- /inner -->
  </section>



  <footer>
    <p class="footer-names">Jheroll & Jennie</p>
    <p class="footer-date">February 6, 2027 · Silang, Cavite</p>
    <p class="footer-love">Made with love </p>
  </footer>

</div><!-- end page-home -->





<!-- ════════════════════════════════════
     PAGE 3: DASHBOARD
═════════════════════════════════════ -->
<div id="page-dashboard" class="page">
  <div class="dash-page">

    <!-- LOGIN -->
    <div class="login-wrap" id="dash-login">
      <p class="login-title">RSVP Dashboard</p>
      <p class="login-sub">Jheroll & Jennie · February 6, 2027</p>
      <div class="login-box">
        <label class="d-label">Password</label>
        <input class="d-input" id="dash-pw" type="password" placeholder="Enter password" onkeydown="if(event.key==='Enter')dashLogin()"/>
        <p class="login-err" id="dash-err">Incorrect password. Try again.</p>
        <button class="login-btn" onclick="dashLogin()">Enter Dashboard</button>
      </div>
    </div>

    <!-- DASHBOARD CONTENT -->
    <div class="dash-inner" id="dash-content">
      <div class="dash-header">
        <div>
          <p class="dash-title">RSVP Dashboard</p>
          <p class="dash-sub">Jheroll & Jennie · February 6, 2027</p>
        </div>
        <div class="dash-btns">
          <button class="d-btn" onclick="loadDash()">↻ Refresh</button>
          <button class="d-btn" onclick="dashLogout()">Log out</button>
        </div>
      </div>
      <div class="stats-grid">
        <div class="stat"><span class="stat-num" id="stat-total">0</span><span class="stat-label">Total Responses</span></div>
        <div class="stat"><span class="stat-num" id="stat-yes">0</span><span class="stat-label">Attending</span></div>
        <div class="stat"><span class="stat-num" id="stat-no">0</span><span class="stat-label">Declining</span></div>
        <div class="stat"><span class="stat-num" id="stat-seats">0</span><span class="stat-label">Total Seats</span></div>
      </div>
      <div class="filters">
        <button class="filter-btn active" id="fb-all" onclick="filterRSVPs('all')">All</button>
        <button class="filter-btn" id="fb-yes" onclick="filterRSVPs('yes')">Attending</button>
        <button class="filter-btn" id="fb-no" onclick="filterRSVPs('no')">Declining</button>
      </div>
      <div class="rsvp-list" id="rsvp-list"></div>
    </div>

  </div>
</div><!-- end page-dashboard -->

<script>

// ── RSVP: search, show the group, save ──
(function () {
  const form  = document.getElementById('rsvp-search');
  if (!form) return;
  const input = document.getElementById('rf-input');
  const list  = document.getElementById('rf-list');
  const hint  = document.getElementById('rf-hint');
  const panel = document.getElementById('rsvp-panel');
  const rows  = document.getElementById('rp-members');
  const msg   = document.getElementById('rp-message');
  const err   = document.getElementById('rp-error');
  const closedNote = document.getElementById('rp-closed');
  const confirmBtn = document.getElementById('rp-confirm');
  const done  = document.getElementById('rp-done');
  const CSRF  = document.querySelector('meta[name="csrf-token"]').content;
  let timer, ctrl, items = [], active = -1, memberCount = 0;

  function say(el, text){ el.textContent = text; el.hidden = !text; }
  function closeList(){ list.hidden = true; input.setAttribute('aria-expanded', 'false'); items = []; active = -1; }
  function note(t){ list.innerHTML = ''; items = []; const li = document.createElement('li'); li.className = 'rf-note'; li.textContent = t; list.appendChild(li); list.hidden = false; }

  function mark(parent, text, q){
    const i = q ? text.toLowerCase().indexOf(q.toLowerCase()) : -1;
    if (i < 0) { parent.append(text); return; }
    parent.append(text.slice(0, i));
    const b = document.createElement('strong');
    b.textContent = text.slice(i, i + q.length);
    parent.append(b, text.slice(i + q.length));
  }

  async function suggest(q, signal){
    const res = await fetch('?suggest=' + encodeURIComponent(q), { signal });
    if (!res.ok) throw new Error('bad response');
    return (await res.json()).parties || [];
  }

  function render(parties, q){
    list.innerHTML = ''; items = []; active = -1;
    if (!parties.length) { note('No match yet. Try another spelling.'); return; }
    parties.forEach((p, i) => {
      const li = document.createElement('li');
      li.className = 'rf-opt'; li.id = 'rf-opt-' + i; li.setAttribute('role', 'option');
      p.names.forEach((n, k) => { if (k) li.append(' · '); mark(li, n, q); });
      const s = document.createElement('small');
      s.textContent = p.names.length + (p.names.length === 1 ? ' seat' : ' seats') + ' reserved';
      li.appendChild(s);
      li.addEventListener('mousedown', e => { e.preventDefault(); openParty(p.match); });
      list.appendChild(li); items.push(li);
    });
    list.hidden = false;
    input.setAttribute('aria-expanded', 'true');
  }

  function highlight(i){
    items.forEach((el, k) => el.setAttribute('aria-selected', k === i ? 'true' : 'false'));
    active = i;
    if (i >= 0) input.setAttribute('aria-activedescendant', items[i].id);
  }

  // ── show the group with a toggle for each person ──
  function buildPanel(data){
    rows.innerHTML = '';
    memberCount = data.members.length;
    data.members.forEach((m, i) => {
      const row = document.createElement('div'); row.className = 'rp-member';
      const name = document.createElement('span'); name.className = 'rp-name'; name.textContent = m.name;
      const tog = document.createElement('div'); tog.className = 'rp-toggle'; tog.setAttribute('role', 'radiogroup'); tog.setAttribute('aria-label', m.name);
      [['Yes', 'Attending'], ['No', 'Not attending']].forEach(([val, label]) => {
        const l = document.createElement('label');
        const r = document.createElement('input');
        r.type = 'radio'; r.name = 'rp-ans-' + i; r.value = val;
        r.checked = (m.status === val); r.disabled = !!data.closed;
        const s = document.createElement('span'); s.textContent = label;
        l.append(r, s); tog.appendChild(l);
      });
      row.append(name, tog); rows.appendChild(row);
    });
    msg.value = '';
    say(err, '');
    closedNote.hidden = !data.closed;
    confirmBtn.parentElement.hidden = !!data.closed;
    msg.disabled = !!data.closed;
    const blank = document.querySelector('.seat-blank');
    if (blank) blank.textContent = memberCount;                // fills "We have reserved __ seat(s)"
    done.hidden = true;
    panel.hidden = false;
    panel.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
  }

  async function openParty(name){
    closeList();
    say(hint, '');
    try {
      const res = await fetch('?open=' + encodeURIComponent(name));
      const data = await res.json();
      if (!data.ok) throw new Error('not found');
      input.value = name;
      buildPanel(data);
    } catch (e) {
      say(hint, 'We couldn’t open that invitation. Please try again.');
    }
  }

  function resetToSearch(clear){
        panel.hidden = true; done.hidden = true;
    closeList(); say(err, ''); say(hint, '');
    if (clear) { input.value = ''; input.focus(); }
  }

  // typing
  input.addEventListener('input', () => {
    say(hint, '');
    if (!panel.hidden || !done.hidden) resetToSearch(false);   // typing a new name starts over
    clearTimeout(timer);
    const q = input.value.trim();
    if (q.length < 3) { ctrl && ctrl.abort(); closeList(); return; }
    timer = setTimeout(async () => {
      ctrl && ctrl.abort(); ctrl = new AbortController();
      try { render(await suggest(q, ctrl.signal), q); }
      catch (e) { if (e.name !== 'AbortError') closeList(); }
    }, 250);
  });

  input.addEventListener('keydown', e => {
    if (list.hidden || !items.length) return;
    if (e.key === 'ArrowDown') { e.preventDefault(); highlight((active + 1) % items.length); }
    else if (e.key === 'ArrowUp') { e.preventDefault(); highlight((active - 1 + items.length) % items.length); }
    else if (e.key === 'Enter' && active >= 0) { e.preventDefault(); items[active].dispatchEvent(new MouseEvent('mousedown')); }
    else if (e.key === 'Escape') { closeList(); }
  });

  document.addEventListener('click', e => { if (!form.contains(e.target)) closeList(); });

  // "Find your Invitation" button / Enter
  form.addEventListener('submit', async e => {
    e.preventDefault();
    const q = input.value.trim();
    if (q.length < 3) { say(hint, q ? 'Please type at least 3 letters.' : 'Please type your name first.'); input.focus(); return; }
    say(hint, '');
    try {
      const parties = await suggest(q);
      if (parties.length === 1) openParty(parties[0].match);          // one match: open it straight away
      else if (parties.length > 1) render(parties, q);                // several: let them choose
      else say(hint, 'We couldn’t find that name. Check the spelling, or try your first or last name.');
    } catch (e2) { say(hint, 'We couldn’t reach the guest list. Please try again.'); }
  });

  // save
  confirmBtn.addEventListener('click', async () => {
    const answers = [];
    for (let i = 0; i < memberCount; i++) {
      const c = panel.querySelector('input[name="rp-ans-' + i + '"]:checked');
      if (!c) { say(err, 'Please choose Attending or Not attending for everyone.'); return; }
      answers.push(c.value);
    }
    say(err, '');
    confirmBtn.disabled = true; confirmBtn.textContent = 'Sending…';
    try {
      const res = await fetch('?save', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': CSRF },
        body: JSON.stringify({ answers, message: msg.value.trim() })
      });
      const data = await res.json().catch(() => ({}));
      if (!res.ok || !data.ok) {
        throw new Error(data.error === 'closed' ? 'The RSVP deadline has passed. Please contact the couple.'
                      : data.error === 'session' ? 'Your session expired. Please refresh the page and try again.'
                      : 'Something went wrong. Please try again.');
      }
      document.getElementById('rp-done-text').textContent = answers.includes('Yes')
        ? 'We can’t wait to celebrate with you.'
        : 'We’ll miss you. Thank you for letting us know.';
      panel.hidden = true; done.hidden = false;
      done.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
    } catch (e) {
      say(err, e.message);
    } finally {
      confirmBtn.disabled = false; confirmBtn.textContent = 'Confirm RSVP ♥';
    }
  });

  document.getElementById('rp-again').addEventListener('click', () => resetToSearch(true));
  document.getElementById('rp-done-again').addEventListener('click', () => resetToSearch(true));
})();
 
// ── PRENUP LIGHTBOX ──
let lightboxIndex = 0;
let lightboxSrcs  = [];

function buildLightboxSrcs() {
  // Collect all prenup images (featured + album)
  const imgs = [];
  document.querySelectorAll('.pf-item img, .pa-item img').forEach(img => imgs.push({ src: img.src, alt: img.alt || '' }));
  // If placeholders (no real imgs yet), push empty
  document.querySelectorAll('.pf-item, .pa-item').forEach((el, i) => {
    const img = el.querySelector('img');
    if (!imgs[i]) imgs[i] = { src: img ? img.src : '', alt: '' };
  });
  lightboxSrcs = imgs;
}

function openLightbox(index) {
  buildLightboxSrcs();
  const src = lightboxSrcs[index]?.src;
  if (!src) return; // no real photo yet — don't open
  lightboxIndex = index;
  document.getElementById('lightbox-img').src = src;
  document.getElementById('lightbox-counter').textContent = (index + 1) + ' of ' + lightboxSrcs.filter(s=>s.src).length;
  document.querySelectorAll('#lightbox .lightbox-prev, #lightbox .lightbox-next, #lightbox-counter').forEach(el => el.style.display = '');
  document.getElementById('lightbox').classList.add('open');
  document.body.style.overflow = 'hidden';
}

function closeLightbox() {
  document.getElementById('lightbox').classList.remove('open');
  document.body.style.overflow = '';
}

function closeLightboxOutside(e) {
  if (e.target === document.getElementById('lightbox')) closeLightbox();
}

function shiftLightbox(dir) {
  buildLightboxSrcs();
  const valid = lightboxSrcs.map((s,i)=>s.src?i:null).filter(i=>i!==null);
  const pos = valid.indexOf(lightboxIndex);
  const next = valid[(pos + dir + valid.length) % valid.length];
  lightboxIndex = next;
  document.getElementById('lightbox-img').src = lightboxSrcs[next].src;
  document.getElementById('lightbox-counter').textContent = (valid.indexOf(next)+1) + ' of ' + valid.length;
}

// Keyboard nav
document.addEventListener('keydown', e => {
  if (!document.getElementById('lightbox').classList.contains('open')) return;
  if (e.key === 'ArrowRight') shiftLightbox(1);
  if (e.key === 'ArrowLeft')  shiftLightbox(-1);
  if (e.key === 'Escape')     closeLightbox();
});

// ── ALBUM TOGGLE ──
let albumOpen = false;
function toggleAlbum(btn) {
  const album = document.getElementById('prenup-album');
  albumOpen = !albumOpen;
  album.style.display = albumOpen ? 'grid' : 'none';
  btn.textContent = albumOpen ? 'Hide Album' : 'View Full Album';
}

// ── PHOTO LOADER ──
function loadPhoto(input, frameId) {
  const file = input.files[0];
  if (!file) return;
  const reader = new FileReader();
  reader.onload = function(e) {
    const frame = input.closest('.t-photo-frame');
    frame.querySelector('.placeholder').style.display = 'none';
    let img = frame.querySelector('img');
    if (!img) {
      img = document.createElement('img');
      frame.insertBefore(img, frame.querySelector('.corner.tl'));
    }
    img.src = e.target.result;
    img.style.cssText = 'width:100%;height:100%;object-fit:cover;display:block;position:absolute;inset:0;';
  };
  reader.readAsDataURL(file);
}

// ── VINYL PLAYER ──
let vinylPlaying = false;

function openVinyl() {
  document.getElementById('vinyl-player').classList.add('visible');
  document.getElementById('vinyl-reopen').classList.remove('show');
}
function closeVinyl() {
  document.getElementById('vinyl-player').classList.remove('visible');
  document.getElementById('vinyl-reopen').classList.add('show');
}

// Listen for Spotify iframe play/pause events to sync disc
window.addEventListener('message', (e) => {
  if (!e.data || typeof e.data !== 'object') return;
  const type = e.data.type || '';
  if (type === 'ready' || type === 'playback_update') {
    const playing = e.data.payload?.isPaused === false;
    syncDisc(playing);
  }
});

function syncDisc(playing) {
  vinylPlaying = playing;
  document.getElementById('vinyl-disc').classList.toggle('playing', playing);
  document.getElementById('vinyl-arm').classList.toggle('playing', playing);
}

// Also toggle disc when user clicks anywhere on the player (best-effort sync)
document.addEventListener('DOMContentLoaded', () => {
  const iframe = document.getElementById('spotify-main');
  if (iframe) {
    iframe.addEventListener('load', () => {
      // Start disc after iframe loads
      setTimeout(() => syncDisc(true), 1000);
    });
  }
});

setTimeout(() => { openVinyl(); }, 1500);

// ── PAGE ROUTING ──
function showPage(name) {
  document.querySelectorAll('.page').forEach(p => p.classList.remove('active'));
  document.getElementById('page-' + name).classList.add('active');
  window.scrollTo(0, 0);
}

// ── FIX SPOTIFY IFRAME SCROLL JUMP ──
// Intercept any focus/scroll caused by iframes
window.addEventListener('message', function(e) {
  // Ignore Spotify postMessages that might cause scroll
}, false);

// Save scroll position and restore if any iframe causes a jump
let lastScrollY = 0;
window.addEventListener('scroll', () => { lastScrollY = window.scrollY; }, { passive: true });

// Detect and fix sudden scroll jumps caused by iframe focus
let prevScrollY = 0;
setInterval(() => {
  const diff = Math.abs(window.scrollY - prevScrollY);
  // If page jumped more than 300px instantly (not a user scroll), restore
  if (diff > 300 && document.activeElement && document.activeElement.tagName === 'IFRAME') {
    window.scrollTo({ top: prevScrollY, behavior: 'instant' });
  }
  prevScrollY = window.scrollY;
}, 50);

// Prevent iframes from stealing focus on track change
document.querySelectorAll('iframe').forEach(f => {
  f.setAttribute('tabindex', '-1');
});

// ── SCROLL REVEAL (re-triggers both ways) ──
const observer = new IntersectionObserver((entries) => {
  entries.forEach(entry => {
    if (entry.isIntersecting) {
      entry.target.classList.add('visible');
    } else {
      // Remove so it re-animates next time it enters
      entry.target.classList.remove('visible');
    }
  });
}, { threshold: 0.12 });

function initReveal() {
  document.querySelectorAll('.reveal, .reveal-left, .reveal-right, .stagger').forEach(el => {
    observer.observe(el);
  });
}
initReveal();

// ── HERO PARALLAX ──
window.addEventListener('scroll', () => {
  const hero = document.querySelector('.hero');
  if (hero) {
    const scrolled = window.pageYOffset;
    hero.style.backgroundPositionY = scrolled * 0.4 + 'px';
    const heroInner = hero.querySelector('.hero-inner');
    if (heroInner) heroInner.style.transform = `translateY(${scrolled * 0.15}px)`;
  }

  // ── ACTIVE NAV HIGHLIGHT ──
  const sections = ['our-story','location','outfit','playlist','prenup','sponsors','entourage'];
  const navLinks = document.querySelectorAll('.nav-links a');
  let current = '';
  sections.forEach(id => {
    const el = document.getElementById(id);
    if (el && window.pageYOffset >= el.offsetTop - 120) current = id;
  });
  navLinks.forEach(a => {
    a.style.color = '';
    if (a.getAttribute('href') === '#' + current) a.style.color = '#C9A84C';
  });

  // ── FIX SPOTIFY SCROLL JUMP ──
  // Prevent Spotify iframe from stealing scroll focus
  const spotifyFrame = document.querySelector('#playlist iframe');
  if (spotifyFrame) {
    spotifyFrame.style.pointerEvents = 'none';
    clearTimeout(window._spotifyTimer);
    window._spotifyTimer = setTimeout(() => {
      spotifyFrame.style.pointerEvents = 'auto';
    }, 300);
  }
}, { passive: true });

// ── FALLING PETALS ──
const PETALS = ['✦','✧','·','⋆','✽'];
function createPetal() {
  const petal = document.createElement('div');
  petal.className = 'petal';
  petal.textContent = PETALS[Math.floor(Math.random() * PETALS.length)];
  petal.style.left = Math.random() * 100 + 'vw';
  petal.style.color = Math.random() > 0.5 ? '#C9A84C' : '#E8B4BB';
  petal.style.fontSize = (Math.random() * 10 + 8) + 'px';
  const dur = Math.random() * 8 + 10;
  petal.style.animationDuration = dur + 's';
  petal.style.animationDelay = Math.random() * 5 + 's';
  document.body.appendChild(petal);
  setTimeout(() => petal.remove(), (dur + 5) * 1000);
}
// Spawn petals gently
setInterval(createPetal, 1800);
for(let i = 0; i < 5; i++) setTimeout(createPetal, i * 600);

// ── RSVP FORM ──
function toggleGuests(el) {
  const gf = document.getElementById('guests-field');
  gf.classList.toggle('show', el.value === 'yes');
}

function submitRSVP() {
  const name = document.getElementById('rsvp-name').value.trim();
  if (!name) {
    document.getElementById('name-err').style.display = 'block';
    return;
  }
  document.getElementById('name-err').style.display = 'none';

  const attending = document.querySelector('input[name="attending"]:checked').value;
  const entry = {
    name,
    email: document.getElementById('rsvp-email').value.trim(),
    attending,
    guests: attending === 'yes' ? document.getElementById('rsvp-guests').value : '0',
    message: document.getElementById('rsvp-message').value.trim(),
    submittedAt: new Date().toISOString()
  };

  // Save to localStorage
  const existing = JSON.parse(localStorage.getItem('wedding_rsvps') || '[]');
  existing.push(entry);
  localStorage.setItem('wedding_rsvps', JSON.stringify(existing));

  // Show success
  const firstName = name.split(' ')[0];
  document.getElementById('rsvp-form-wrap').style.display = 'none';
  document.getElementById('rsvp-success').style.display = 'block';
  if (attending === 'yes') {
    document.getElementById('success-title').textContent = 'See you there!';
    document.getElementById('success-text').innerHTML = 'Salamat, ' + firstName + '!<br>We\'re so excited to celebrate with you.<br>See you on February 6, 2027.';
  } else {
    document.getElementById('success-title').textContent = 'We\'ll miss you!';
    document.getElementById('success-text').innerHTML = 'Thank you for letting us know, ' + firstName + '.<br>You\'ll be in our hearts on our special day.';
  }
}

// ── DASHBOARD ──
const DASH_PASSWORD = 'jherollandjennie2027';
let allRSVPs = [];
let currentFilter = 'all';

function dashLogin() {
  const pw = document.getElementById('dash-pw').value;
  if (pw === DASH_PASSWORD) {
    document.getElementById('dash-login').style.display = 'none';
    document.getElementById('dash-content').style.display = 'block';
    loadDash();
  } else {
    document.getElementById('dash-err').style.display = 'block';
    setTimeout(() => document.getElementById('dash-err').style.display = 'none', 2500);
  }
}

function dashLogout() {
  document.getElementById('dash-login').style.display = 'flex';
  document.getElementById('dash-content').style.display = 'none';
  document.getElementById('dash-pw').value = '';
}

function loadDash() {
  allRSVPs = JSON.parse(localStorage.getItem('wedding_rsvps') || '[]')
    .sort((a, b) => new Date(b.submittedAt) - new Date(a.submittedAt));

  const yes = allRSVPs.filter(r => r.attending === 'yes');
  const no  = allRSVPs.filter(r => r.attending === 'no');
  const seats = yes.reduce((s, r) => s + parseInt(r.guests || 0), 0);

  document.getElementById('stat-total').textContent = allRSVPs.length;
  document.getElementById('stat-yes').textContent   = yes.length;
  document.getElementById('stat-no').textContent    = no.length;
  document.getElementById('stat-seats').textContent = seats;

  filterRSVPs(currentFilter);
}

function filterRSVPs(f) {
  currentFilter = f;
  document.querySelectorAll('.filter-btn').forEach(b => b.classList.remove('active'));
  document.getElementById('fb-' + f).classList.add('active');

  const shown = f === 'all' ? allRSVPs : allRSVPs.filter(r => r.attending === f);
  const list = document.getElementById('rsvp-list');

  if (shown.length === 0) {
    list.innerHTML = '<div class="empty-state">' + (allRSVPs.length === 0 ? 'No RSVPs received yet.' : 'No responses in this category.') + '</div>';
    return;
  }

  list.innerHTML = shown.map(r => {
    const initials = r.name.trim().split(' ').map(w => w[0] || '').slice(0,2).join('').toUpperCase();
    const date = r.submittedAt ? new Date(r.submittedAt).toLocaleDateString('en-PH', {year:'numeric',month:'long',day:'numeric',hour:'2-digit',minute:'2-digit'}) : '';
    return `
      <div class="rsvp-card-d">
        <div class="rsvp-avatar ${r.attending}">${initials}</div>
        <div class="rsvp-body">
          <p class="rsvp-dname">${r.name}</p>
          ${r.email ? `<p class="rsvp-demail">${r.email}</p>` : ''}
          <div class="rsvp-meta">
            <span class="badge badge-${r.attending}">${r.attending === 'yes' ? '✓ Attending' : '✗ Declining'}</span>
            ${r.attending === 'yes' ? `<span class="badge badge-seats">${r.guests} ${parseInt(r.guests) === 1 ? 'seat' : 'seats'}</span>` : ''}
          </div>
          ${r.message ? `<p class="rsvp-msg-d">"${r.message}"</p>` : ''}
          <p class="rsvp-ddate">${date}</p>
        </div>
      </div>`;
  }).join('');
}

// ══════════════════════════════════════
//  FLOATING LEAVES & FLOWERS
// ══════════════════════════════════════

// SVG shapes: leaves, petals, blossoms
const FLORA = {
  // Simple pointed leaf
  leaf1: (c) => `<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 40 60" width="40" height="60">
    <path d="M20,55 C20,55 2,38 2,22 C2,10 10,2 20,2 C30,2 38,10 38,22 C38,38 20,55 20,55 Z" fill="${c}" opacity="0.18"/>
    <line x1="20" y1="55" x2="20" y2="8" stroke="${c}" stroke-width="0.8" opacity="0.25"/>
    <line x1="20" y1="30" x2="10" y2="20" stroke="${c}" stroke-width="0.5" opacity="0.2"/>
    <line x1="20" y1="30" x2="30" y2="20" stroke="${c}" stroke-width="0.5" opacity="0.2"/>
    <line x1="20" y1="40" x2="12" y2="32" stroke="${c}" stroke-width="0.5" opacity="0.2"/>
    <line x1="20" y1="40" x2="28" y2="32" stroke="${c}" stroke-width="0.5" opacity="0.2"/>
  </svg>`,

  // Elongated tropical leaf
  leaf2: (c) => `<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 30 70" width="30" height="70">
    <path d="M15,68 C15,68 1,45 1,28 C1,12 8,1 15,1 C22,1 29,12 29,28 C29,45 15,68 15,68 Z" fill="${c}" opacity="0.15"/>
    <path d="M15,68 C8,50 3,38 3,28 C3,15 8,5 15,2" fill="none" stroke="${c}" stroke-width="0.7" opacity="0.22"/>
    <line x1="15" y1="20" x2="5" y2="14" stroke="${c}" stroke-width="0.5" opacity="0.18"/>
    <line x1="15" y1="20" x2="25" y2="14" stroke="${c}" stroke-width="0.5" opacity="0.18"/>
    <line x1="15" y1="32" x2="4" y2="24" stroke="${c}" stroke-width="0.5" opacity="0.18"/>
    <line x1="15" y1="32" x2="26" y2="24" stroke="${c}" stroke-width="0.5" opacity="0.18"/>
    <line x1="15" y1="44" x2="6" y2="36" stroke="${c}" stroke-width="0.5" opacity="0.15"/>
    <line x1="15" y1="44" x2="24" y2="36" stroke="${c}" stroke-width="0.5" opacity="0.15"/>
  </svg>`,

  // Round blossom / sampaguita-style
  flower1: (c, cc) => `<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 60 60" width="60" height="60">
    <g transform="translate(30,30)">
      <ellipse cx="0" cy="-16" rx="7" ry="13" fill="${c}" opacity="0.2"/>
      <ellipse cx="0" cy="-16" rx="7" ry="13" fill="${c}" opacity="0.2" transform="rotate(45)"/>
      <ellipse cx="0" cy="-16" rx="7" ry="13" fill="${c}" opacity="0.2" transform="rotate(90)"/>
      <ellipse cx="0" cy="-16" rx="7" ry="13" fill="${c}" opacity="0.2" transform="rotate(135)"/>
      <ellipse cx="0" cy="-16" rx="7" ry="13" fill="${c}" opacity="0.2" transform="rotate(180)"/>
      <ellipse cx="0" cy="-16" rx="7" ry="13" fill="${c}" opacity="0.2" transform="rotate(225)"/>
      <ellipse cx="0" cy="-16" rx="7" ry="13" fill="${c}" opacity="0.2" transform="rotate(270)"/>
      <ellipse cx="0" cy="-16" rx="7" ry="13" fill="${c}" opacity="0.2" transform="rotate(315)"/>
      <circle r="9" fill="${cc}" opacity="0.25"/>
    </g>
  </svg>`,

  // 5-petal flower
  flower2: (c, cc) => `<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 50 50" width="50" height="50">
    <g transform="translate(25,25)">
      <ellipse cx="0" cy="-13" rx="6" ry="11" fill="${c}" opacity="0.22"/>
      <ellipse cx="0" cy="-13" rx="6" ry="11" fill="${c}" opacity="0.22" transform="rotate(72)"/>
      <ellipse cx="0" cy="-13" rx="6" ry="11" fill="${c}" opacity="0.22" transform="rotate(144)"/>
      <ellipse cx="0" cy="-13" rx="6" ry="11" fill="${c}" opacity="0.22" transform="rotate(216)"/>
      <ellipse cx="0" cy="-13" rx="6" ry="11" fill="${c}" opacity="0.22" transform="rotate(288)"/>
      <circle r="7" fill="${cc}" opacity="0.3"/>
    </g>
  </svg>`,

  // Small 4-petal blossom
  flower3: (c, cc) => `<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 40 40" width="40" height="40">
    <g transform="translate(20,20)">
      <ellipse cx="0" cy="-10" rx="5" ry="9" fill="${c}" opacity="0.2"/>
      <ellipse cx="0" cy="-10" rx="5" ry="9" fill="${c}" opacity="0.2" transform="rotate(90)"/>
      <ellipse cx="0" cy="-10" rx="5" ry="9" fill="${c}" opacity="0.2" transform="rotate(180)"/>
      <ellipse cx="0" cy="-10" rx="5" ry="9" fill="${c}" opacity="0.2" transform="rotate(270)"/>
      <circle r="6" fill="${cc}" opacity="0.28"/>
    </g>
  </svg>`,

  // Curved fern-style leaf
  leaf3: (c) => `<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 50 80" width="50" height="80">
    <path d="M10,75 C10,75 5,55 8,40 C11,25 20,10 35,5 C28,15 22,28 20,42 C18,56 20,68 10,75 Z" fill="${c}" opacity="0.16"/>
    <path d="M10,75 C10,75 5,55 8,40 C11,25 20,10 35,5" fill="none" stroke="${c}" stroke-width="0.8" opacity="0.2"/>
    <line x1="12" y1="58" x2="3" y2="50" stroke="${c}" stroke-width="0.5" opacity="0.15"/>
    <line x1="15" y1="46" x2="5" y2="36" stroke="${c}" stroke-width="0.5" opacity="0.15"/>
    <line x1="19" y1="33" x2="10" y2="22" stroke="${c}" stroke-width="0.5" opacity="0.15"/>
    <line x1="24" y1="20" x2="17" y2="10" stroke="${c}" stroke-width="0.5" opacity="0.15"/>
  </svg>`,
};

const LEAF_COLORS  = ['#7D1D30','#C9A84C','#9B2335','#5C1523','#A08060','#C4908A'];
const CENTER_COLORS = ['#E8D5A3','#FDF6EE','#F5E6E8','#E8B4BB'];

function randomFrom(arr){ return arr[Math.floor(Math.random()*arr.length)]; }

function spawnFloraItem() {
  const container = document.getElementById('flora-layer');
  if (!container) return;

  const type = Math.random();
  const c  = randomFrom(LEAF_COLORS);
  const cc = randomFrom(CENTER_COLORS);

  let svgStr;
  if      (type < 0.18) svgStr = FLORA.leaf1(c);
  else if (type < 0.36) svgStr = FLORA.leaf2(c);
  else if (type < 0.50) svgStr = FLORA.leaf3(c);
  else if (type < 0.65) svgStr = FLORA.flower1(c, cc);
  else if (type < 0.80) svgStr = FLORA.flower2(c, cc);
  else                   svgStr = FLORA.flower3(c, cc);

  const el = document.createElement('div');
  el.style.cssText = `
    position:fixed;
    pointer-events:none;
    z-index:1;
    left:${Math.random()*105 - 2}vw;
    top:-120px;
    opacity:0;
    transform-origin: center center;
  `;
  el.innerHTML = svgStr;

  const size   = Math.random() * 0.8 + 0.5;           // 0.5–1.3×
  const dur    = Math.random() * 14 + 12;              // 12–26s
  const drift  = (Math.random() - 0.5) * 220;          // -110 to +110px horizontal drift
  const spin   = (Math.random() - 0.5) * 540;          // rotation
  const delay  = Math.random() * 3;

  el.style.transform = `scale(${size})`;

  container.appendChild(el);

  // Animate with keyframes via Web Animations API
  el.animate([
    { opacity: 0,   transform: `scale(${size}) translateX(0px)       translateY(0px)   rotate(0deg)` },
    { opacity: 0.9, transform: `scale(${size}) translateX(${drift*0.3}px) translateY(25vh)  rotate(${spin*0.3}deg)`, offset: 0.08 },
    { opacity: 0.75,transform: `scale(${size}) translateX(${drift*0.6}px) translateY(55vh)  rotate(${spin*0.6}deg)`, offset: 0.5 },
    { opacity: 0.5, transform: `scale(${size}) translateX(${drift*0.85}px)translateY(80vh)  rotate(${spin*0.85}deg)`,offset: 0.8 },
    { opacity: 0,   transform: `scale(${size}) translateX(${drift}px)     translateY(108vh) rotate(${spin}deg)` },
  ], {
    duration: dur * 1000,
    delay: delay * 1000,
    easing: 'ease-in-out',
    fill: 'forwards',
  }).onfinish = () => el.remove();
}

// Spawn flora continuously
function startFlora() {
  // Initial burst
  for (let i = 0; i < 8; i++) {
    setTimeout(spawnFloraItem, i * 700);
  }
  // Ongoing — one every 2.2s
  setInterval(spawnFloraItem, 2200);
}

// Also spawn stationary background flora decorations (fixed positions, very subtle)
function addStaticFlora() {
  const layer = document.getElementById('flora-layer');
  if (!layer) return;

  const positions = [
    { left:'2%',  top:'18%',  size:1.4, rot: 15,  type:'f1' },
    { left:'92%', top:'12%',  size:1.2, rot:-20,  type:'l2' },
    { left:'5%',  top:'55%',  size:1.0, rot: 30,  type:'f2' },
    { left:'88%', top:'48%',  size:1.3, rot:-10,  type:'l1' },
    { left:'95%', top:'72%',  size:1.1, rot: 25,  type:'f3' },
    { left:'1%',  top:'80%',  size:1.2, rot:-15,  type:'l3' },
    { left:'50%', top:'5%',   size:0.9, rot:  5,  type:'f1' },
    { left:'75%', top:'90%',  size:1.0, rot:-25,  type:'f2' },
    { left:'25%', top:'95%',  size:1.1, rot: 10,  type:'l1' },
  ];

  const animations = [
    'staticSway1','staticSway2','staticSway3',
    'staticSway1','staticSway2','staticSway3',
    'staticSway1','staticSway2','staticSway3',
  ];

  positions.forEach((p, i) => {
    const c  = randomFrom(LEAF_COLORS);
    const cc = randomFrom(CENTER_COLORS);
    let svgStr;
    if      (p.type === 'l1') svgStr = FLORA.leaf1(c);
    else if (p.type === 'l2') svgStr = FLORA.leaf2(c);
    else if (p.type === 'l3') svgStr = FLORA.leaf3(c);
    else if (p.type === 'f1') svgStr = FLORA.flower1(c, cc);
    else if (p.type === 'f2') svgStr = FLORA.flower2(c, cc);
    else                      svgStr = FLORA.flower3(c, cc);

    const el = document.createElement('div');
    el.style.cssText = `
      position:fixed;
      pointer-events:none;
      z-index:1;
      left:${p.left};
      top:${p.top};
      transform:scale(${p.size}) rotate(${p.rot}deg);
      opacity:0.55;
    `;
    el.innerHTML = svgStr;
    layer.appendChild(el);

    // Gentle sway animation
    const swayAmt = (Math.random() * 12 + 6);
    const swayDur = Math.random() * 8 + 10;
    el.animate([
      { transform: `scale(${p.size}) rotate(${p.rot}deg) translateX(0px)` },
      { transform: `scale(${p.size}) rotate(${p.rot + swayAmt}deg) translateX(${swayAmt*0.5}px)` },
      { transform: `scale(${p.size}) rotate(${p.rot}deg) translateX(0px)` },
    ], {
      duration: swayDur * 1000,
      iterations: Infinity,
      easing: 'ease-in-out',
      delay: i * 800,
    });
  });
}

// Inject the flora layer div
document.addEventListener('DOMContentLoaded', () => {
  const layer = document.createElement('div');
  layer.id = 'flora-layer';
  layer.style.cssText = 'position:fixed;inset:0;pointer-events:none;z-index:1;overflow:hidden;';
  document.body.appendChild(layer);

  addStaticFlora();
  startFlora();
});

  const bgm = document.getElementById('bgm');
  const START = 1;          // seconds to skip, change as needed
  bgm.volume = 0.5;

  function seekStart(){ bgm.currentTime = START; }

  // Set the start point once the file info has loaded
  if (bgm.readyState >= 1) seekStart();
  else bgm.addEventListener('loadedmetadata', seekStart, {once:true});

  // Manual loop: when the song ends, go back to START instead of 0
  bgm.addEventListener('ended', () => {
    seekStart();
    bgm.play();
  });

  const events = ['pointerdown','touchend','click','keydown'];
  function removeListeners(){
    events.forEach(e => window.removeEventListener(e, unlock));
  }
  function unlock(){
    bgm.play().then(removeListeners).catch(() => {});
  }
  bgm.play().catch(() => {
    events.forEach(e => window.addEventListener(e, unlock));
  });

  const s = new URLSearchParams(location.search).get('seats');
  if (s) document.querySelector('.seat-blank').textContent = s;

const lb = document.getElementById('lightbox');
const lbImg = document.getElementById('lightbox-img');
const lbNav = lb.querySelectorAll('.lightbox-prev, .lightbox-next, .lightbox-counter');

// "How we met" photos: open without arrows
document.querySelectorAll('.zoomable').forEach(frame => {
  frame.addEventListener('click', () => {
    const img = frame.querySelector('img');
    lbImg.src = img.src;
    lbImg.alt = img.alt;
    lbNav.forEach(el => el.style.display = 'none');
    lb.classList.add('open');
    document.body.style.overflow = 'hidden';
  });
});

</script>



</body>
</html>
