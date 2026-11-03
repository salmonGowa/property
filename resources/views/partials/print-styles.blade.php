<style>
        :root { --ink: #1f2937; --muted: #6b7280; --rule: #e5e7eb; --accent: #4f46e5; }
        * { box-sizing: border-box; }
        body { font-family: system-ui, -apple-system, "Segoe UI", Roboto, sans-serif; color: var(--ink); margin: 0; background: #f3f4f6; font-size: 14px; }
        .page { position: relative; max-width: 820px; margin: 24px auto; background: #fff; padding: 40px; border-radius: 8px; box-shadow: 0 1px 3px rgba(0,0,0,.08); overflow: hidden; }
        header { display: flex; justify-content: space-between; gap: 24px; flex-wrap: wrap; border-bottom: 3px solid var(--accent); padding-bottom: 16px; margin-bottom: 24px; }
        h1 { margin: 0; font-size: 22px; }
        .title { text-align: right; }
        .title h2 { margin: 0; font-size: 26px; letter-spacing: .08em; color: var(--accent); }
        .muted { color: var(--muted); }
        .parties { display: grid; grid-template-columns: 1fr 1fr; gap: 24px; margin-bottom: 24px; }
        .parties h3 { margin: 0 0 6px; font-size: 12px; text-transform: uppercase; letter-spacing: .05em; color: var(--muted); }
        dl { display: grid; grid-template-columns: auto 1fr; gap: 4px 12px; margin: 0; }
        dt { color: var(--muted); }
        dd { margin: 0; }
        table { width: 100%; border-collapse: collapse; }
        th, td { padding: 10px 6px; border-bottom: 1px solid var(--rule); text-align: left; }
        th { font-size: 12px; text-transform: uppercase; letter-spacing: .04em; color: var(--muted); }
        .num { text-align: right; white-space: nowrap; font-variant-numeric: tabular-nums; }
        .totals { margin-left: auto; width: 320px; max-width: 100%; margin-top: 16px; }
        .totals td { border: 0; padding: 4px 6px; }
        .totals .grand td { border-top: 2px solid var(--ink); font-weight: 700; font-size: 16px; padding-top: 8px; }
        .pay { margin-top: 32px; padding: 16px; border: 1px dashed var(--accent); border-radius: 6px; background: #eef2ff; }
        .stamp { position: absolute; top: 120px; right: -40px; transform: rotate(20deg); font-size: 48px; font-weight: 800; letter-spacing: .1em; padding: 4px 60px; opacity: .18; }
        .stamp.paid { color: #059669; border: 6px solid #059669; }
        .stamp.void { color: #dc2626; border: 6px solid #dc2626; }
        .toolbar { max-width: 820px; margin: 16px auto 0; display: flex; justify-content: flex-end; padding: 0 16px; }
        .toolbar button { background: var(--accent); color: #fff; border: 0; padding: 8px 16px; border-radius: 6px; font-size: 14px; cursor: pointer; }
        @media (max-width: 640px) { .page { margin: 0; padding: 20px; border-radius: 0; } .parties { grid-template-columns: 1fr; } .title { text-align: left; } }
        @media print { body { background: #fff; } .toolbar { display: none; } .page { box-shadow: none; margin: 0; max-width: none; } }
</style>
