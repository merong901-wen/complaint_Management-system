<?php
session_start();

// Redirect logged-in users
if(isset($_SESSION['user_logged_in'])){
    require_once __DIR__ . '/includes/app.php';
    redirect_for_role();
}

require_once __DIR__ . '/admin/config/config.php';

$siteMetrics = ['total' => 0, 'active' => 0, 'resolved' => 0, 'categories' => []];$metricsAvailable = false;

try {
    // Fetch total, resolved, and active metrics
    $metricsResult =$conn->query("SELECT
        COUNT(*) AS total,
        SUM(CASE WHEN LOWER(REPLACE(status, ' ', '')) = 'resolved' THEN 1 ELSE 0 END) AS resolved,
        SUM(CASE WHEN LOWER(REPLACE(status, ' ', '')) IN ('pending', 'inprogress') THEN 1 ELSE 0 END) AS active
        FROM complaints");

    if ($metricsResult) {
        $metricsRow =$metricsResult->fetch_assoc();
        
        // Fetch top categories for realistic campus insight
        $catResult =$conn->query("SELECT category, COUNT(*) as count FROM complaints GROUP BY category ORDER BY count DESC LIMIT 3");
        $topCategories = [];
        if ($catResult) {
            while ($row =$catResult->fetch_assoc()) {
                $topCategories[] =$row;
            }
        }

        $siteMetrics = [
            'total' => (int) $metricsRow['total'],
            'active' => (int) $metricsRow['active'],
            'resolved' => (int) $metricsRow['resolved'],
            'categories' => $topCategories
        ];
        $metricsAvailable = true;
    }
} catch (Throwable $exception) {
    error_log('Homepage metrics query failed: ' . $exception->getMessage());
}

if (isset($_GET['metrics'])) {
    header('Content-Type: application/json');
    header('Cache-Control: no-store, no-cache, must-revalidate');
    http_response_code($metricsAvailable ? 200 : 503);
    echo json_encode($siteMetrics + ['available' => $metricsAvailable]);
    exit();
}

$resolutionRate =$siteMetrics['total'] > 0
    ? (int) round(($siteMetrics['resolved'] /$siteMetrics['total']) * 100)
    : 0;
?>

<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Student Complaint & Support System</title>

<!-- Font Awesome for Icons -->
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">

<!-- Project CSS -->
<link rel="stylesheet" href="public/assets/css/style.css?v=20261015.5">

<style>
:root { --home-blue: #8b7cf6; --home-black: #111827; --home-white: #fff; --home-accent: #7565e8; --home-warm: #f0b44b; }
body { min-height: 100vh; font-family: 'Poppins', sans-serif; background-image: radial-gradient(rgba(139,124,246,.07) 1px, transparent 1px) !important; background-size: 22px 22px; }
.site-shell { width: min(1220px, calc(100% - 48px)); margin: 0 auto; }
.site-header { position: sticky; top: 10px; z-index: 1000; min-height: 72px; display: flex; align-items: center; justify-content: space-between; gap: 20px; margin: 10px 0 18px; padding: 10px 16px; border: 1px solid var(--system-border); border-radius: 14px; background: color-mix(in srgb, var(--system-surface) 92%, transparent); box-shadow: 0 10px 30px rgba(20,45,75,.08); backdrop-filter: blur(14px); }
.header-widget { display: flex; align-items: center; gap: 15px; font-size: 12px; font-weight: 500; }
.primary-nav { display: flex; align-items: center; justify-content: flex-end; gap: 8px; }
.primary-nav > a, .nav-explore > summary { display: inline-flex; align-items: center; min-height: 38px; padding: 0 11px; border-radius: 7px; color: var(--system-text) !important; font-size: 12px; font-weight: 600; text-decoration: none; transition: background .18s ease, color .18s ease, transform .18s ease; cursor: pointer; }
.primary-nav > a:hover, .nav-explore.has-current > summary { background: color-mix(in srgb, var(--home-blue) 12%, transparent); color: var(--home-accent) !important; }
.nav-explore { position: relative; }
.nav-explore > summary { list-style: none; gap: 8px; }
.nav-explore > summary::-webkit-details-marker { display: none; }
.nav-explore > summary::after { content: ''; width: 7px; height: 7px; border-right: 1.5px solid currentColor; border-bottom: 1.5px solid currentColor; transform: rotate(45deg) translateY(-2px); transition: transform .18s ease; }
.nav-explore[open] > summary::after { transform: rotate(225deg) translate(-1px,-1px); }
.nav-dropdown { position: absolute; top: calc(100% + 8px); left: 0; min-width: 190px; padding: 7px; border: 1px solid var(--system-border); border-radius: 11px; background: var(--system-surface); box-shadow: 0 16px 36px rgba(20,45,75,.16); animation: rise-in .16s ease both; }
.primary-nav .nav-dropdown a { display: flex; min-height: 38px; align-items: center; padding: 0 10px; border-radius: 7px; color: var(--system-text) !important; font-size: 12px; font-weight: 600; text-decoration: none; }
.primary-nav .nav-dropdown a:hover, .primary-nav .nav-dropdown a.is-current { background: color-mix(in srgb, var(--home-blue) 12%, transparent); color: var(--home-accent) !important; }
.primary-nav a.nav-cta { background: var(--home-blue); color: #fff !important; }
.primary-nav a.nav-cta:hover { background: var(--home-accent); color: #fff !important; transform: translateY(-1px); }
.header-theme-toggle { width: 38px; height: 38px; display: inline-grid; place-items: center; padding: 0; border: 1px solid var(--system-border); border-radius: 7px; background: var(--system-surface); color: var(--system-text); cursor: pointer; font-size: 13px; }
.header-theme-toggle:hover { border-color: var(--home-blue); color: var(--home-accent); }
.mobile-nav-toggle { display: none; align-items: center; justify-content: center; width: 42px; height: 42px; border: 1px solid var(--system-border); border-radius: 8px; background: var(--system-surface); color: var(--system-text); cursor: pointer; }
.reading-progress { position: fixed; z-index: 1100; top: 0; left: 0; width: 0; height: 3px; background: linear-gradient(90deg, var(--home-blue), #20c997); transition: width .08s linear; }
.live-clock { display: flex; align-items: center; gap: 8px; background: var(--system-surface); padding: 8px 13px; border-radius: 5px; border: 1px solid var(--system-border); box-shadow: 0 4px 12px rgba(20,45,75,.05); }
.brand { display: inline-flex; align-items: center; gap: 12px; color: var(--home-black) !important; text-decoration: none; }
.brand-mark { display: grid; width: 44px; height: 44px; place-items: center; background: var(--home-blue); color: var(--home-white); font-size: 15px; font-weight: 700; border-radius: 8px; box-shadow: 0 8px 20px rgba(139,124,246,.25); }
.brand-copy { display: grid; gap: 1px; }
.brand-copy strong { font-size: 16px; line-height: 1.2; }
.brand-copy small { font-size: 12px; font-weight: 600; }
.overview { display: grid; grid-template-columns: minmax(0, 1.15fr) minmax(320px, .85fr); min-height: 430px; overflow: hidden; border: 1px solid var(--system-border); border-radius: 12px; background: var(--system-surface); box-shadow: 0 18px 48px rgba(20,45,75,.09); }
.overview-copy { display: flex; flex-direction: column; justify-content: center; align-items: flex-start; padding: 52px 58px; background: linear-gradient(135deg, rgba(139,124,246,.08), rgba(139,124,246,.04)); animation: rise-in .55s ease both; }
.eyebrow { display: flex; align-items: center; gap: 9px; margin: 0 0 18px; color: var(--home-accent); font-size: 12px; font-weight: 700; }
.eyebrow i { font-size: 14px; }
.overview h1 { max-width: 620px; margin: 0; font-size: 46px; line-height: 1.1; }
.overview-description { max-width: 530px; margin: 20px 0 26px; font-size: 16px; line-height: 1.7; }
.action-row { display: flex; flex-wrap: wrap; gap: 12px; margin-bottom: 20px; }
body a.action-primary, body a.action-secondary { display: inline-flex; align-items: center; justify-content: center; gap: 10px; min-height: 48px; padding: 0 18px; border: 1px solid var(--home-blue); border-radius: 3px; font-weight: 600; text-decoration: none; transition: all 0.2s; }
body a.action-primary { background: var(--home-blue); color: var(--home-white) !important; }
body a.action-primary:hover { opacity: 0.9; transform: translateY(-1px); }
body a.action-secondary { background: transparent; color: var(--home-blue) !important; }
body a.action-secondary:hover { background: rgba(13, 110, 253, 0.05); }

/* Quick Tracker Bar */
.quick-track-box { width: 100%; max-width: 530px; background: rgba(0,0,0,0.02); padding: 12px; border: 1px dashed rgba(0,0,0,0.2); border-radius: 6px; display: flex; gap: 8px; align-items: center; }
.quick-track-box input { flex: 1; border: 1px solid #ccc; padding: 8px 12px; border-radius: 4px; font-size: 12px; outline: none; }
.quick-track-box button { background: var(--home-black); color: var(--home-white); border: none; padding: 8px 14px; border-radius: 4px; font-size: 12px; cursor: pointer; }
.quick-track-hint { display: block; max-width: 530px; margin-top: 7px; color: var(--system-muted); font-size: 11px; }
.visually-hidden { position: absolute !important; width: 1px !important; height: 1px !important; padding: 0 !important; margin: -1px !important; overflow: hidden !important; clip: rect(0,0,0,0) !important; white-space: nowrap !important; border: 0 !important; }

.service-note { display: flex; align-items: center; gap: 9px; margin-top: 20px; font-size: 12px; }
.service-indicator { width: 8px; height: 8px; background: var(--home-accent); border-radius: 50%; box-shadow: 0 0 0 3px rgba(25, 135, 84, 0.2); }
.pulse-panel { display: flex; flex-direction: column; justify-content: center; padding: 34px; background: linear-gradient(145deg, #0e1b17, #111827); color: var(--home-white); border-left: 4px solid var(--home-accent); animation: rise-in .55s .1s ease both; }
.pulse-heading { display: flex; align-items: center; justify-content: space-between; padding-bottom: 16px; border-bottom: 1px solid rgba(255,255,255,.2); }
.pulse-heading h2 { margin: 0; color: var(--home-white) !important; font-size: 15px; font-weight: 600; }
.pulse-time { margin-left: auto; margin-right: 10px; color: #d4e0eb; font-size: 11px; font-variant-numeric: tabular-nums; }
.refresh-button { display: grid; width: 38px; height: 38px; place-items: center; border: 1px solid rgba(255,255,255,.35); background: rgba(255,255,255,.06); color: var(--home-white); cursor: pointer; border-radius: 6px; }
.refresh-button:hover { background: var(--home-blue); }
.refresh-button.is-refreshing i { animation: spin .7s linear infinite; }
.metrics-grid { display: grid; grid-template-columns: 1fr 1fr; }
.metric { min-height: 102px; padding: 17px 12px 14px 0; border-bottom: 1px solid rgba(255,255,255,.2); }
.metric-link { display: block; color: inherit !important; text-decoration: none; transition: background-color .18s ease, padding .18s ease; }
.metric-link:hover, .metric-link:focus-visible { background: rgba(255,255,255,.08); padding-left: 10px; }
.metric-link:focus-visible { outline: 2px solid var(--home-warm); outline-offset: -2px; }
.metric:nth-child(odd) { border-right: 1px solid var(--home-white); }
.metric:nth-child(even) { padding-left: 18px; }
.metric-value { display: block; color: var(--home-blue); font-size: 32px; line-height: 1.1; font-weight: 700; }
.metric-label { display: block; margin-top: 8px; color: var(--home-white); font-size: 12px; }
.system-insight { min-height: 48px; margin: 16px 0 0; font-size: 12px; line-height: 1.6; color: #d4e0eb; }
.update-stamp { margin: 0; color: #bdc8d3; font-size: 12px; }
.process-section { padding: 34px 0 30px; }
.student-guidance { margin: 12px 0 18px; padding: 24px; border: 1px solid var(--system-border); border-radius: 10px; background: var(--system-surface); box-shadow: 0 12px 32px rgba(20,45,75,.06); }
.guidance-heading { display: flex; align-items: flex-end; justify-content: space-between; gap: 16px; margin-bottom: 16px; }
.guidance-heading h2 { margin: 0; font-size: 21px; }
.guidance-heading p { max-width: 480px; margin: 0; color: var(--system-muted); font-size: 12px; }
.guidance-grid { display: grid; grid-template-columns: repeat(3, minmax(0, 1fr)); gap: 10px; }
.guidance-rule { display: grid; grid-template-columns: 32px 1fr; gap: 10px; min-height: 106px; padding: 14px; border: 1px solid var(--system-border); border-radius: 7px; background: var(--system-page); }
.guidance-number { display: grid; width: 30px; height: 30px; place-items: center; border-radius: 6px; background: rgba(139,124,246,.1); color: var(--home-blue); font-size: 12px; font-weight: 700; }
.guidance-rule h3 { margin: 1px 0 5px; font-size: 14px; }
.guidance-rule p { margin: 0; color: var(--system-muted); font-size: 12px; line-height: 1.55; }
.guidance-warning { margin: 12px 0 0; padding: 12px 14px; border-left: 4px solid var(--home-warm); border-radius: 5px; background: rgba(240,180,75,.12); font-size: 12px; line-height: 1.55; }
.report-planner { display: grid; grid-template-columns: minmax(0,1fr) auto; gap: 18px 24px; align-items: center; margin-top: 18px; padding: 18px 20px; border: 1px solid var(--system-border); border-radius: 9px; background: var(--system-page); }
.report-planner h3 { margin: 0 0 4px; font-size: 15px; }
.report-planner-intro { margin: 0 0 12px; color: var(--system-muted); font-size: 12px; }
.planner-checks { display: grid; grid-template-columns: repeat(3,minmax(0,1fr)); gap: 9px; }
.planner-check { display: flex; align-items: flex-start; gap: 8px; padding: 10px; border: 1px solid var(--system-border); border-radius: 7px; background: var(--system-surface); color: var(--system-text); font-size: 11px; cursor: pointer; transition: border-color .18s ease, transform .18s ease; }
.planner-check:hover { transform: translateY(-2px); border-color: var(--home-blue); }
.planner-check input { flex: 0 0 auto; margin-top: 2px; }
.planner-progress { min-width: 220px; }
.planner-count { display: flex; justify-content: space-between; gap: 8px; margin-bottom: 7px; color: var(--system-muted); font-size: 11px; }
.planner-track { height: 7px; overflow: hidden; border-radius: 8px; background: var(--system-border); }
.planner-track span { display: block; width: 0; height: 100%; border-radius: inherit; background: var(--home-blue); transition: width .25s ease; }
.planner-status { min-height: 18px; margin: 7px 0 10px; color: var(--system-muted); font-size: 11px; }
.planner-action { width: 100%; min-height: 38px; padding: 0 12px; border: 0; border-radius: 6px; background: var(--home-blue); color: #fff; font-size: 12px; font-weight: 600; cursor: pointer; transition: opacity .2s ease, transform .2s ease; }
.planner-action:hover:not(:disabled) { transform: translateY(-1px); }
.planner-action:disabled { cursor: not-allowed; opacity: .45; }
.section-heading { display: flex; align-items: baseline; justify-content: space-between; gap: 20px; margin-bottom: 20px; }
.section-heading h2 { margin: 0; font-size: 21px; }
.section-heading p { margin: 0; font-size: 12px; }
.process-grid { display: grid; grid-template-columns: repeat(3, 1fr); gap: 12px; }
.process-step { display: grid; grid-template-columns: 38px 1fr; gap: 12px; padding: 20px; border: 1px solid var(--system-border); border-radius: 8px; background: var(--system-surface); color: var(--system-text) !important; text-decoration: none; box-shadow: 0 8px 22px rgba(20,45,75,.05); transition: transform .18s ease, box-shadow .18s ease, border-color .18s ease; }
.process-step:hover { transform: translateY(-3px); border-color: var(--home-blue); box-shadow: 0 14px 30px rgba(20,45,75,.12); }
.process-step:focus-visible { outline: 3px solid rgba(139,124,246,.35); outline-offset: 3px; }
.step-number { color: var(--home-blue); font-size: 12px; font-weight: 700; }
.process-step h3 { margin: 0 0 5px; font-size: 14px; }
.process-step p { margin: 0; font-size: 12px; line-height: 1.6; }
.site-footer { display: flex; justify-content: space-between; gap: 16px; padding: 20px 0 28px; font-size: 12px; border-top: 1px solid rgba(0,0,0,0.1); margin-top: 20px; }
.site-footer p { margin: 0; }
.faq-section { margin: 12px 0 28px; padding: 22px 24px; border: 1px solid var(--system-border); border-radius: 10px; background: var(--system-surface); box-shadow: 0 12px 32px rgba(20,45,75,.05); }
.faq-section h2 { margin: 0 0 14px; font-size: 20px; }
.faq-item { border-top: 1px solid var(--system-border); }
.faq-item summary { display: flex; align-items: center; justify-content: space-between; gap: 15px; padding: 14px 2px; cursor: pointer; color: var(--system-text); font-size: 13px; font-weight: 600; list-style: none; }
.faq-item summary::-webkit-details-marker { display: none; }
.faq-item summary::after { content: '+'; color: var(--home-blue); font-size: 20px; transition: transform .18s ease; }
.faq-item[open] summary::after { content: '−'; transform: rotate(180deg); }
.faq-item p { margin: -3px 0 15px; color: var(--system-muted); font-size: 12px; line-height: 1.65; }
.home-visual { display: grid; grid-template-columns: minmax(0, 1fr) minmax(180px, .65fr); align-items: center; gap: 24px; margin: 18px 0; padding: 16px 26px; overflow: hidden; border: 1px solid var(--system-border); border-radius: 12px; background: linear-gradient(110deg, var(--system-surface), color-mix(in srgb, var(--home-blue) 9%, var(--system-surface))); }
.home-visual-copy { padding: 12px 0; }
.home-visual-copy .eyebrow { margin-bottom: 8px; }
.home-visual-copy h2 { margin: 0 0 8px; font-size: 20px; }
.home-visual-copy p:last-child { max-width: 620px; margin: 0; color: var(--system-muted); font-size: 13px; line-height: 1.65; }
.home-visual img { display: block; width: min(100%, 300px); max-height: 190px; justify-self: center; object-fit: contain; filter: drop-shadow(0 12px 14px rgba(50,40,120,.12)); animation: rise-in .65s .1s ease both; }
.skip-link { position: fixed; top: 8px; left: 8px; z-index: 1200; transform: translateY(-150%); padding: 10px 14px; border-radius: 6px; background: var(--system-sidebar); color: #fff !important; }
.skip-link:focus { transform: translateY(0); }

/* Dark mode overrides */
body.dark-mode { background: var(--system-page) !important; color: var(--system-text) !important; }
body.dark-mode .brand { color: var(--system-text) !important; }
body.dark-mode .primary-nav > a, body.dark-mode .nav-explore > summary { color: var(--system-text) !important; }
body.dark-mode .nav-explore.has-current > summary, body.dark-mode .primary-nav > a:hover { color: var(--system-text) !important; background: rgba(169,156,255,.15); }
body.dark-mode .primary-nav a.nav-cta, body.dark-mode .primary-nav a.nav-cta:hover { background: var(--home-blue); color: #fff !important; }
body.dark-mode .nav-dropdown { background: var(--system-surface); border-color: var(--system-border); }
body.dark-mode .primary-nav .nav-dropdown a, body.dark-mode .primary-nav .nav-dropdown a:hover, body.dark-mode .primary-nav .nav-dropdown a.is-current { color: var(--system-text) !important; }
body.dark-mode .primary-nav .nav-dropdown a:hover, body.dark-mode .primary-nav .nav-dropdown a.is-current { background: rgba(169,156,255,.15); }
body.dark-mode .header-theme-toggle, body.dark-mode .mobile-nav-toggle { background: var(--system-surface); color: var(--system-text); border-color: var(--system-border); }
body.dark-mode .overview { border-color: var(--system-border); background: var(--system-surface); }
body.dark-mode .overview-copy { background: linear-gradient(135deg, rgba(139,124,246,.12), rgba(139,124,246,.08)); }
body.dark-mode .process-step { border-color: var(--system-border); background: var(--system-surface); color: var(--system-text) !important; }
body.dark-mode .pulse-panel { border-color: var(--home-accent); background: linear-gradient(145deg, #0e1b17, #111827); }
body.dark-mode .pulse-heading { border-color: rgba(255,255,255,.2); }
body.dark-mode .quick-track-box { background: var(--system-surface); border-color: var(--system-border); }
body.dark-mode .quick-track-box input { background: var(--system-page); border-color: var(--system-border); color: var(--system-text); }
body.dark-mode .site-footer { border-color: var(--system-border); }
body.dark-mode .faq-section, body.dark-mode .home-visual { background: var(--system-surface); }

@keyframes rise-in { from { opacity: 0; transform: translateY(10px); } to { opacity: 1; transform: translateY(0); } }
@keyframes spin { to { transform: rotate(360deg); } }

@media (max-width: 760px) {
    .site-shell { width: min(100% - 32px, 560px); }
    .site-header { min-height: 68px; padding: 8px 12px; }
    .brand-mark { width: 40px; height: 40px; }
    .mobile-nav-toggle { display: inline-flex; margin-left: auto; }
    .primary-nav { position: absolute; top: calc(100% + 8px); left: 0; right: 0; display: none; align-items: stretch; flex-direction: column; gap: 4px; padding: 10px; border: 1px solid var(--system-border); border-radius: 12px; background: var(--system-surface); box-shadow: 0 18px 40px rgba(20,45,75,.15); }
    .primary-nav.is-open { display: flex; animation: rise-in .18s ease both; }
    .primary-nav > a, .nav-explore > summary { width: 100%; min-height: 42px; justify-content: flex-start; padding: 0 12px; }
    .nav-explore { width: 100%; }
    .nav-dropdown { position: static; min-width: 0; margin: 2px 0 4px; box-shadow: none; animation: none; }
    .header-theme-toggle { width: 100%; height: 42px; justify-content: flex-start; padding: 0 12px; gap: 10px; }
    .overview { grid-template-columns: 1fr; }
    .overview-copy { padding: 32px 24px; }
    .overview h1 { font-size: 36px; }
    .pulse-panel { padding: 24px; border-left: 0; border-top: 1px solid var(--home-blue); }
    .process-grid { grid-template-columns: 1fr; }
    .process-step { padding: 16px; }
    .section-heading { align-items: flex-start; flex-direction: column; gap: 5px; }
    .student-guidance { padding: 18px; }
    .guidance-heading { align-items: flex-start; flex-direction: column; gap: 6px; }
    .guidance-grid { grid-template-columns: repeat(2, minmax(0, 1fr)); }
    .report-planner { grid-template-columns: 1fr; }
    .planner-checks { grid-template-columns: 1fr; }
    .planner-progress { min-width: 0; }
    .site-footer { flex-direction: column; }
    .header-widget { display: none; }
    .faq-section { padding: 18px; }
    .home-visual { grid-template-columns: 1fr; gap: 4px; padding: 18px; }
    .home-visual img { width: min(100%, 250px); max-height: 150px; }
}
@media (max-width: 520px) { .guidance-grid { grid-template-columns: 1fr; } }
@media (prefers-reduced-motion: reduce) {
    *, *::before, *::after { animation-duration: .01ms !important; animation-iteration-count: 1 !important; scroll-behavior: auto !important; }
}
</style>
</head>
<body>

<a class="skip-link" href="#main-content">Skip to content</a>
<div class="reading-progress" id="reading-progress" role="progressbar" aria-label="Reading progress" aria-valuemin="0" aria-valuemax="100" aria-valuenow="0"></div>
<div class="site-shell">
    <header class="site-header">
        <a class="brand" href="index.php" aria-label="Student Support home">
            <span class="brand-mark" aria-hidden="true">CS</span>
            <span class="brand-copy"><strong>Student Support</strong><small>COMPLAINT RESOLUTION</small></span>
        </a>
        <button type="button" class="mobile-nav-toggle" id="mobile-nav-toggle" aria-controls="primary-nav" aria-expanded="false" aria-label="Open navigation"><i class="fa-solid fa-bars" aria-hidden="true"></i></button>
        <nav class="primary-nav" id="primary-nav" aria-label="Main navigation">
            <details class="nav-explore" id="nav-explore">
                <summary><i class="fa-solid fa-compass" aria-hidden="true"></i> Explore</summary>
                <div class="nav-dropdown">
                    <a href="#how-it-works">How it works</a>
                    <a href="#guidance">Student guide</a>
                    <a href="#system-pulse">System status</a>
                    <a href="#faq">Quick answers</a>
                </div>
            </details>
            <a href="login.php">Sign in</a>
            <a class="nav-cta" href="register.php">Create account</a>
            <button type="button" class="header-theme-toggle" data-theme-toggle aria-pressed="false" aria-label="Switch to dark mode" title="Toggle dark mode"><i class="fa-solid fa-moon" data-theme-icon aria-hidden="true"></i><span class="visually-hidden" data-theme-label>Dark mode</span></button>
        </nav>
    </header>

    <main id="main-content">
        <section class="overview" aria-labelledby="overview-title">
            <div class="overview-copy">
                <p class="eyebrow" id="dynamic-greeting"><i class="fa-solid fa-life-ring" aria-hidden="true"></i> STUDENT SUPPORT PORTAL</p>
                <h1 id="overview-title">Every concern deserves a clear next step.</h1>
                <p class="overview-description">Submit academic or campus grievances, securely follow progress transparently, and stay updated in real time.</p>
                
                <div class="action-row">
                    <a href="login.php" class="action-primary"><i class="fa-solid fa-arrow-right-to-bracket" aria-hidden="true"></i> login</a>
                    <a href="register.php" class="action-secondary"><i class="fa-solid fa-user-plus" aria-hidden="true"></i> Create account</a>
                </div>

                <!-- Realistic Smart Feature: Quick Ticket Lookup Form Preview -->
                <form class="quick-track-box" onsubmit="handleQuickTrack(event)" role="search" aria-label="Find a complaint">
                    <label class="visually-hidden" for="quick-ticket-id">Ticket number or complaint keyword</label>
                    <input type="search" id="quick-ticket-id" placeholder="Enter ticket number or keyword" autocomplete="off" required>
                    <button type="submit"><i class="fa-solid fa-magnifying-glass" aria-hidden="true"></i> Track</button>
                </form>
                <small class="quick-track-hint">We will take you to sign in, then search complaints linked to your account.</small>

                <div class="service-note">
                    <span class="service-indicator" aria-hidden="true"></span>
                    <span><?= $metricsAvailable ? 'Secure Database Online & Synchronized' : 'System overview is temporarily unavailable' ?></span>
                </div>
            </div>

            <aside class="pulse-panel" id="system-pulse" aria-labelledby="pulse-title">
                <div class="pulse-heading">
                    <h2 id="pulse-title">System pulse</h2>
                    <time class="pulse-time" id="live-clock-text">--:--:--</time>
                    <button type="button" id="refresh-metrics" class="refresh-button" aria-label="Refresh system metrics" title="Refresh now">
                        <i class="fa-solid fa-rotate" aria-hidden="true"></i>
                    </button>
                </div>
                <div class="metrics-grid" aria-live="polite">
                    <a class="metric metric-link" href="login.php" aria-label="Sign in to view complaints"><strong class="metric-value" data-metric="total"><?= $metricsAvailable ? number_format($siteMetrics['total']) : '--' ?></strong><span class="metric-label">All complaints</span></a>
                    <a class="metric metric-link" href="login.php" aria-label="Sign in to view active complaints"><strong class="metric-value" data-metric="active"><?= $metricsAvailable ? number_format($siteMetrics['active']) : '--' ?></strong><span class="metric-label">Being handled</span></a>
                    <a class="metric metric-link" href="login.php" aria-label="Sign in to view resolved complaints"><strong class="metric-value" data-metric="resolved"><?= $metricsAvailable ? number_format($siteMetrics['resolved']) : '--' ?></strong><span class="metric-label">Resolved</span></a>
                    <a class="metric metric-link" href="login.php" aria-label="Sign in to view resolution details"><strong class="metric-value" data-metric="rate"><?= $metricsAvailable ? $resolutionRate . '%' : '--' ?></strong><span class="metric-label">Resolution rate</span></a>
                </div>
                <p id="system-insight" class="system-insight" role="status">Loading system insight...</p>
                <p id="metrics-updated" class="update-stamp"><?= $metricsAvailable ? 'Updated just now · refreshes automatically' : 'Live metrics are currently unavailable' ?></p>
            </aside>
        </section>

        <section class="home-visual" aria-labelledby="home-visual-title">
            <div class="home-visual-copy"><p class="eyebrow"><i class="fa-solid fa-comments" aria-hidden="true"></i> SUPPORT THAT FOLLOWS THROUGH</p><h2 id="home-visual-title">A clear conversation, from concern to next step.</h2><p>Share the details once, then use your account to follow updates from the staff handling your case.</p></div>
            <img src="public/assets/images/home-support-illustration.svg" alt="Illustration of a student sharing a concern and a support staff member marking it for follow-up" width="520" height="300" loading="lazy">
        </section>

        <section class="student-guidance" id="guidance" aria-labelledby="guidance-title">
            <header class="guidance-heading">
                <div><p class="eyebrow mb-2">STUDENT GUIDE</p><h2 id="guidance-title">Before you raise a concern</h2></div>
                <p>Use the right channel, give staff time to respond, and help us keep reports fair and useful.</p>
            </header>
            <div class="guidance-grid">
                <article class="guidance-rule"><span class="guidance-number">01</span><div><h3>Wait for approval</h3><p>Your student account must be approved by your department head before you can submit a complaint.</p></div></article>
                <article class="guidance-rule"><span class="guidance-number">02</span><div><h3>Report accurately</h3><p>Give clear, truthful details and send regular complaints directly to the assigned teacher.</p></div></article>
                <article class="guidance-rule"><span class="guidance-number">03</span><div><h3>Allow one week</h3><p>Give your teacher up to seven days to respond and work toward a resolution before escalating.</p></div></article>
                <article class="guidance-rule"><span class="guidance-number">04</span><div><h3>Escalate appropriately</h3><p>Contact your department head for sensitive matters or an unresolved case that has already gone to your teacher.</p></div></article>
                <article class="guidance-rule"><span class="guidance-number">05</span><div><h3>Use the system responsibly</h3><p>False, unsupported, abusive, or disruptive use may be reviewed and could lead to disciplinary action under school policy.</p></div></article>
            </div>
            <section class="report-planner" aria-labelledby="planner-title">
                <div>
                    <h3 id="planner-title">Smart report planner</h3>
                    <p class="report-planner-intro">Prepare a clear report before you begin. Your checklist stays on this device.</p>
                    <div class="planner-checks">
                        <label class="planner-check"><input type="checkbox" data-planner-item value="facts"><span>Describe what happened using clear facts.</span></label>
                        <label class="planner-check"><input type="checkbox" data-planner-item value="when-where"><span>Note when and where it happened.</span></label>
                        <label class="planner-check"><input type="checkbox" data-planner-item value="outcome"><span>Decide what help or outcome you are seeking.</span></label>
                    </div>
                </div>
                <div class="planner-progress">
                    <div class="planner-count"><span>Report readiness</span><span id="planner-count">0 of 3</span></div>
                    <div class="planner-track" role="progressbar" aria-label="Report planner progress" aria-valuemin="0" aria-valuemax="3" aria-valuenow="0"><span id="planner-progress-fill"></span></div>
                    <p class="planner-status" id="planner-status" aria-live="polite">Check each item as you prepare your report.</p>
                    <button type="button" class="planner-action" id="planner-action" disabled>Continue to create account</button>
                </div>
            </section>
        </section>

        <section class="process-section" id="how-it-works" aria-labelledby="process-title">
            <div class="section-heading">
                <h2 id="process-title">A clear path from report to resolution</h2>
                <p>Every case stays connected to its status and updates.</p>
            </div>
            <div class="process-grid">
                <a class="process-step" href="register.php"><span class="step-number">01</span><div><h3>Submit</h3><p>Describe the issue, attach files, and assign category priority.</p></div></a>
                <a class="process-step" href="login.php"><span class="step-number">02</span><div><h3>Track</h3><p>Check department progress notes and admin communication live.</p></div></a>
                <a class="process-step" href="login.php"><span class="step-number">03</span><div><h3>Resolve</h3><p>Review final resolution feedback and verify case closure.</p></div></a>
            </div>
        </section>

        <section class="faq-section" id="faq" aria-labelledby="faq-title">
            <h2 id="faq-title">Quick answers</h2>
            <details class="faq-item"><summary>When can I submit a complaint?</summary><p>Your student account needs department approval before you can sign in and submit a complaint.</p></details>
            <details class="faq-item"><summary>How do I follow up on a complaint?</summary><p>Sign in with your student account to find your complaints, read updates, and reply where the case allows.</p></details>
            <details class="faq-item"><summary>What should I include in a report?</summary><p>Describe what happened, when and where it happened, and the outcome you are seeking. Keep the details accurate and respectful.</p></details>
        </section>
    </main>

    <footer class="site-footer">
        <p>Student Complaint Resolution System v2.4</p>
        <p>&copy; <?= date('Y') ?> · Secure campus network access</p>
    </footer>
</div>

<script src="public/assets/js/script.js?v=20261015.5"></script>
<script>
const mobileNavToggle = document.getElementById('mobile-nav-toggle');
const primaryNav = document.getElementById('primary-nav');
const exploreMenu = document.getElementById('nav-explore');
mobileNavToggle.addEventListener('click', () => {
    const isOpen = mobileNavToggle.getAttribute('aria-expanded') !== 'true';
    mobileNavToggle.setAttribute('aria-expanded', String(isOpen));
    mobileNavToggle.setAttribute('aria-label', isOpen ? 'Close navigation' : 'Open navigation');
    mobileNavToggle.innerHTML = `<i class="fa-solid ${isOpen ? 'fa-xmark' : 'fa-bars'}" aria-hidden="true"></i>`;
    primaryNav.classList.toggle('is-open', isOpen);
});
primaryNav.addEventListener('click', (event) => {
    if (event.target.closest('a')) {
        exploreMenu.open = false;
        if (window.matchMedia('(max-width: 760px)').matches) {
            primaryNav.classList.remove('is-open');
            mobileNavToggle.setAttribute('aria-expanded', 'false');
            mobileNavToggle.setAttribute('aria-label', 'Open navigation');
            mobileNavToggle.innerHTML = '<i class="fa-solid fa-bars" aria-hidden="true"></i>';
        }
    }
});
document.addEventListener('click', (event) => {
    if (!exploreMenu.contains(event.target)) exploreMenu.open = false;
});
document.addEventListener('keydown', (event) => {
    if (event.key === 'Escape' && exploreMenu.open) {
        exploreMenu.open = false;
        exploreMenu.querySelector('summary').focus();
    }
    if (event.key === 'Escape' && primaryNav.classList.contains('is-open')) {
        primaryNav.classList.remove('is-open');
        mobileNavToggle.setAttribute('aria-expanded', 'false');
        mobileNavToggle.setAttribute('aria-label', 'Open navigation');
        mobileNavToggle.innerHTML = '<i class="fa-solid fa-bars" aria-hidden="true"></i>';
        mobileNavToggle.focus();
    }
});

const progressBar = document.getElementById('reading-progress');
let progressFrame = 0;
window.addEventListener('scroll', () => {
    if (progressFrame) return;
    progressFrame = window.requestAnimationFrame(() => {
        const scrollable = document.documentElement.scrollHeight - window.innerHeight;
        const progress = scrollable > 0 ? Math.min(100, Math.round((window.scrollY / scrollable) * 100)) : 0;
        progressBar.style.width = `${progress}%`;
        progressBar.setAttribute('aria-valuenow', String(progress));
        progressFrame = 0;
    });
}, { passive: true });

const navSections = ['how-it-works', 'guidance', 'system-pulse', 'faq'].map((id) => document.getElementById(id));
const navLinks = Array.from(exploreMenu.querySelectorAll('.nav-dropdown a[href^="#"]'));
if ('IntersectionObserver' in window) {
    const sectionObserver = new IntersectionObserver((entries) => {
        entries.forEach((entry) => {
            if (!entry.isIntersecting) return;
            let hasCurrent = false;
            navLinks.forEach((link) => {
                const current = link.hash === `#${entry.target.id}`;
                hasCurrent = hasCurrent || current;
                link.classList.toggle('is-current', current);
                if (current) link.setAttribute('aria-current', 'location');
                else link.removeAttribute('aria-current');
            });
            exploreMenu.classList.toggle('has-current', hasCurrent);
        });
    }, { rootMargin: '-20% 0px -65% 0px' });
    navSections.forEach((section) => sectionObserver.observe(section));
}

const plannerKey = 'complaint-report-planner-v1';
const plannerItems = Array.from(document.querySelectorAll('[data-planner-item]'));
const plannerCount = document.getElementById('planner-count');
const plannerStatus = document.getElementById('planner-status');
const plannerProgress = document.querySelector('.planner-track[role="progressbar"]');
const plannerFill = document.getElementById('planner-progress-fill');
const plannerAction = document.getElementById('planner-action');
try {
    const savedPlanner = JSON.parse(localStorage.getItem(plannerKey) || '[]');
    plannerItems.forEach((item) => { item.checked = savedPlanner.includes(item.value); });
} catch {}
function updatePlanner() {
    const readyCount = plannerItems.filter((item) => item.checked).length;
    plannerCount.textContent = `${readyCount} of ${plannerItems.length}`;
    plannerProgress.setAttribute('aria-valuenow', String(readyCount));
    plannerFill.style.width = `${(readyCount / plannerItems.length) * 100}%`;
    plannerAction.disabled = readyCount !== plannerItems.length;
    plannerStatus.textContent = readyCount === plannerItems.length
        ? 'Your report plan is ready. Continue when you are ready to create an account.'
        : `${plannerItems.length - readyCount} ${plannerItems.length - readyCount === 1 ? 'item' : 'items'} left to prepare.`;
    try { localStorage.setItem(plannerKey, JSON.stringify(plannerItems.filter((item) => item.checked).map((item) => item.value))); } catch {}
}
plannerItems.forEach((item) => item.addEventListener('change', updatePlanner));
plannerAction.addEventListener('click', () => {
    if (plannerAction.disabled) return;
    try { localStorage.removeItem(plannerKey); } catch {}
    window.location.href = 'register.php';
});
updatePlanner();

// Live Dynamic Time & Greeting Feature
function updateClockAndGreeting() {
    const now = new Date();
    document.getElementById('live-clock-text').textContent = now.toLocaleTimeString([], { hour: '2-digit', minute: '2-digit', second: '2-digit' });
    
    const hour = now.getHours();
    let greetText = "STUDENT SUPPORT PORTAL";
    if (hour < 12) greetText = "GOOD MORNING · STUDENT SUPPORT PORTAL";
    else if (hour < 18) greetText = "GOOD AFTERNOON · STUDENT SUPPORT PORTAL";
    else greetText = "GOOD EVENING · STUDENT SUPPORT PORTAL";
    
    const eyebrow = document.getElementById('dynamic-greeting');
    if (eyebrow) {
        eyebrow.innerHTML = `<i class="fa-solid fa-life-ring" aria-hidden="true"></i> ${greetText}`;
    }
}
setInterval(updateClockAndGreeting, 1000);
updateClockAndGreeting();

// Carry the search into the student complaint list after sign-in.
function handleQuickTrack(e) {
    e.preventDefault();
    const query = document.getElementById('quick-ticket-id').value.trim();
    if(query) {
        window.location.href = `login.php?redirect=track&ref=${encodeURIComponent(query)}`;
    }
}

const metricElements = document.querySelectorAll('[data-metric]');
const refreshButton = document.getElementById('refresh-metrics');
const updatedLabel = document.getElementById('metrics-updated');
const insightLabel = document.getElementById('system-insight');

function renderMetrics(metrics) {
    const rate = metrics.total > 0 ? Math.round((metrics.resolved / metrics.total) * 100) : 0;
    const values = { ...metrics, rate: `${rate}%` };

    metricElements.forEach((element) => {
        const value = values[element.dataset.metric];
        element.textContent = element.dataset.metric === 'rate' ? value : Number(value).toLocaleString();
    });
    if (metrics.total === 0) {
        insightLabel.textContent = 'No complaints have been submitted yet. The support desk is ready.';
    } else if (metrics.active === 0) {
        insightLabel.textContent = 'All submitted complaints are fully resolved. Excellent response rate!';
    } else {
        const share = Math.round((metrics.active / metrics.total) * 100);
        let extraText = "";
        if (metrics.categories && metrics.categories.length > 0) {
            extraText = ` Top active category: ${metrics.categories[0].category}.`;
        }
        insightLabel.textContent = `${metrics.active} ${metrics.active === 1 ? 'case is' : 'cases are'} currently being handled (${share}% of all cases).${extraText}`;
    }

    updatedLabel.textContent = `Updated ${new Date().toLocaleTimeString([], { hour: '2-digit', minute: '2-digit' })} · refreshes automatically`;
}

async function refreshMetrics() {
    refreshButton.classList.add('is-refreshing');
    refreshButton.disabled = true;

    try {
        const response = await fetch(`index.php?metrics=1&_=${Date.now()}`, { cache: 'no-store' });
        if (!response.ok) throw new Error('Metrics request failed');
        const metrics = await response.json();
        if (!metrics.available) throw new Error('Metrics unavailable');
        renderMetrics(metrics);
    } catch (error) {
        updatedLabel.textContent = 'Could not refresh live metrics';
    } finally {
        refreshButton.classList.remove('is-refreshing');
        refreshButton.disabled = false;
    }
}

refreshButton.addEventListener('click', refreshMetrics);
renderMetrics(<?= json_encode($siteMetrics + ['available' => $metricsAvailable]) ?>);
window.setInterval(refreshMetrics, 60000);
</script>

</body>
</html>
