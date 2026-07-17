<style>
:root{
  --bg:#0f1117;--s1:#1a1d27;--s2:#252836;--s3:#2e3347;
  --text:#e1e1e1;--muted:#8892a4;
  --blue:#4e9af1;--green:#2ecc71;--red:#e74c3c;--orange:#f39c12;
}
*{box-sizing:border-box;margin:0;padding:0;-webkit-tap-highlight-color:transparent;}
body{background:var(--bg);color:var(--text);font-family:'Segoe UI',system-ui,sans-serif;display:flex;min-height:100vh;}
a{color:var(--blue);text-decoration:none;}

/* ── SIDEBAR ── */
.sidebar{width:220px;background:var(--s1);border-right:1px solid var(--s3);padding:20px 0;position:fixed;top:0;left:0;height:100vh;overflow-y:auto;z-index:200;transition:transform .25s ease;}
.logo{padding:0 18px 18px;font-size:1.1rem;font-weight:700;color:var(--blue);border-bottom:1px solid var(--s3);margin-bottom:8px;}
.ns{padding:10px 18px 4px;font-size:.7rem;color:var(--muted);text-transform:uppercase;letter-spacing:.08em;}
.ni{display:flex;align-items:center;gap:10px;padding:11px 18px;color:var(--muted);font-size:.9rem;border-left:3px solid transparent;transition:.1s;}
.ni:hover{background:var(--s2);color:var(--text);}
.ni.active{background:var(--s2);color:var(--blue);border-left-color:var(--blue);}

/* ── MAIN ── */
.main{margin-left:220px;flex:1;min-height:100vh;}
.topbar{background:var(--s1);border-bottom:1px solid var(--s3);padding:12px 20px;display:flex;align-items:center;justify-content:space-between;position:sticky;top:0;z-index:100;}
.tbar-title{font-size:1.05rem;font-weight:600;}
.tbar-right{display:flex;align-items:center;gap:10px;font-size:.82rem;color:var(--muted);}
.content{padding:16px;}

/* ── OVERLAY (mobile) ── */
.sidebar-overlay{display:none;position:fixed;inset:0;background:#00000080;z-index:150;}

/* ── CARDS ── */
.card{background:var(--s1);border:1px solid var(--s3);border-radius:12px;padding:16px;}
.card-title{font-size:.75rem;color:var(--muted);text-transform:uppercase;letter-spacing:.06em;margin-bottom:6px;}
.card-value{font-size:1.5rem;font-weight:700;}
.card-sub{font-size:.8rem;color:var(--muted);margin-top:4px;}
.divider{border:none;border-top:1px solid var(--s3);margin:14px 0;}

/* ── GRIDS ── */
.g3{display:grid;grid-template-columns:repeat(3,1fr);gap:12px;margin-bottom:16px;}
.g2{display:grid;grid-template-columns:repeat(2,1fr);gap:12px;margin-bottom:16px;}

/* ── TABLE ── */
.tbl{width:100%;border-collapse:collapse;font-size:.85rem;}
.tbl th{text-align:left;padding:9px 12px;background:var(--s2);color:var(--muted);font-weight:500;font-size:.73rem;text-transform:uppercase;}
.tbl td{padding:9px 12px;border-bottom:1px solid var(--s3);vertical-align:middle;}
.tbl tr:last-child td{border-bottom:none;}
.tbl tr:hover td{background:var(--s2);}

/* ── BADGES ── */
.badge{display:inline-flex;padding:3px 9px;border-radius:20px;font-size:.73rem;font-weight:600;}
.badge-exp{background:#e74c3c22;color:var(--red);}
.badge-inc{background:#2ecc7122;color:var(--green);}
.badge-tra{background:#4e9af122;color:var(--blue);}

/* ── BUTTONS ── */
.btn{display:inline-flex;align-items:center;justify-content:center;gap:6px;padding:9px 16px;border-radius:8px;border:none;font-size:.88rem;font-weight:600;cursor:pointer;transition:.15s;text-decoration:none;white-space:nowrap;}
.btn-primary{background:var(--blue);color:#fff;}
.btn-primary:hover{background:#3d87d8;}
.btn-success{background:var(--green);color:#fff;}
.btn-danger{background:var(--red);color:#fff;}
.btn-ghost{background:var(--s2);color:var(--text);border:1px solid var(--s3);}
.btn-ghost:hover{background:var(--s3);}
.btn-sm{padding:6px 11px;font-size:.78rem;}

/* ── FORMS ── */
.fg{margin-bottom:14px;}
.fl{display:block;font-size:.83rem;color:var(--muted);margin-bottom:5px;font-weight:500;}
.fc{width:100%;padding:10px 13px;background:var(--s2);border:1px solid var(--s3);border-radius:8px;color:var(--text);font-size:.9rem;outline:none;}
.fc:focus{border-color:var(--blue);}
select.fc option{background:var(--s2);}
.form-group{margin-bottom:16px;}
.form-label{display:block;margin-bottom:6px;font-size:.85rem;color:var(--muted);font-weight:500;}
.form-control{width:100%;padding:10px 13px;background:var(--s2);border:1px solid var(--s3);border-radius:8px;color:var(--text);font-size:.9rem;outline:none;}
.form-control:focus{border-color:var(--blue);}
select.form-control option{background:var(--s2);}
.form-row{display:grid;grid-template-columns:1fr 1fr;gap:14px;}
.r2{display:grid;grid-template-columns:1fr 1fr;gap:12px;}

/* ── ALERTS ── */
.alert{padding:11px 15px;border-radius:8px;margin-bottom:14px;font-size:.88rem;}
.alert-success{background:#2ecc7122;border:1px solid #2ecc71;color:#2ecc71;}
.alert-danger{background:#e74c3c22;border:1px solid #e74c3c;color:#e74c3c;}
.alert-info{background:#4e9af122;border:1px solid #4e9af1;color:#4e9af1;}

/* ── MISC ── */
.c-blue{color:var(--blue);}.c-green{color:var(--green);}.c-red{color:var(--red);}.c-orange{color:var(--orange);}.c-muted{color:var(--muted);}
.scroll-y{overflow-y:auto;}
.scroll-y::-webkit-scrollbar{width:4px;}
.scroll-y::-webkit-scrollbar-thumb{background:var(--s3);border-radius:4px;}
.section-header{display:flex;align-items:center;justify-content:space-between;margin-bottom:14px;}
.section-title{font-size:1rem;font-weight:600;}
.gap-2{display:flex;gap:8px;flex-wrap:wrap;align-items:center;}
.hint{font-size:.72rem;color:var(--muted);margin-top:3px;}
.menu-toggle{display:none;background:none;border:none;color:var(--text);font-size:1.4rem;cursor:pointer;padding:4px 8px;line-height:1;}

/* ══════════════════════════════
   MOBILE (≤ 768px)
══════════════════════════════ */
@media(max-width:768px){
  /* Sidebar becomes drawer */
  .sidebar{transform:translateX(-100%);width:260px;box-shadow:4px 0 20px #00000060;}
  .sidebar.open{transform:translateX(0);}
  .sidebar-overlay.open{display:block;}
  .menu-toggle{display:block;}

  /* Main fills full width */
  .main{margin-left:0;}
  .content{padding:12px;}
  .topbar{padding:10px 14px;}
  .tbar-title{font-size:.95rem;}

  /* Stack all grids */
  .g3,.g2,.form-row,.r2{grid-template-columns:1fr;}
  .g3,.g2{gap:10px;margin-bottom:12px;}

  /* Smaller cards */
  .card{padding:14px;}
  .card-value{font-size:1.3rem;}

  /* Tables scroll horizontally */
  .tbl-wrap{overflow-x:auto;-webkit-overflow-scrolling:touch;}
  .tbl{min-width:520px;}
  .tbl th,.tbl td{padding:8px 10px;font-size:.8rem;}

  /* Buttons full width in forms */
  .btn{padding:10px 14px;font-size:.85rem;}
  .btn-sm{padding:7px 10px;font-size:.76rem;}

  /* Forms */
  .fc,.form-control{padding:11px 12px;font-size:.95rem;} /* bigger tap targets */
  select.fc,select.form-control{height:44px;}

  /* Topbar date hide on small screens */
  .tbar-right .date-display{display:none;}

  /* Section headers */
  .section-title{font-size:.92rem;}
}

/* ══════════════════════════════
   VERY SMALL (≤ 380px)
══════════════════════════════ */
@media(max-width:380px){
  .content{padding:10px;}
  .topbar{padding:9px 12px;}
  .card-value{font-size:1.2rem;}
  .tbar-title{font-size:.88rem;}
}
</style>
