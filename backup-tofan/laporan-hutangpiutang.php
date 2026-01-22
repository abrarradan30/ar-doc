// 9 Nov 2025 - laporan hutang piutang
@php
use Illuminate\Support\Facades\DB;

$req = app()->request;

// ---- Filter jenis_po (hilangkan NBSP -> spasi biasa), tetap opsional dari request
$jenisPoRaw = (string) ($req->get('jenis_po') ?? '');
$jenisPo = preg_replace('/\x{00A0}/u', ' ', $jenisPoRaw);
$jenisPo = trim($jenisPo);

// ============================ UTIL ============================
// Determine export type (common param names) so we can switch formatting when
// exporting to Excel/CSV. When viewing in the browser show readable Rupiah
// ("Rp 209.683.942"). When exporting to Excel, emit plain integers (no
// thousand separators) so Excel reads the cell as a numeric value.
$exportTypeRaw = (string) ($req->get('tipe_export') ?? $req->get('type_export') ?? $req->get('export') ?? '');
$exportType = strtolower(trim($exportTypeRaw));
$isExcelExport = in_array($exportType, ['excel','xls','xlsx','csv']);

if ($isExcelExport) {
// Excel-friendly: no thousand separator, 0 decimals -> plain integer
$fmtRupiah = fn($v) => number_format((float)$v, 0, '', '');
$fmtBrowser = fn($v) => 'Rp ' . number_format(abs((float)$v), 0, ',', '.');
$fmtExportPlain = fn($v) => number_format((float)$v, 0, '', '');
// Use escaped double-quote sequence \0022 to wrap Rp so Excel shows "Rp" prefix
// while the cell remains numeric. This is a widely used mso-number-format for
// HTML->XLS exports that Excel understands.
$cellNumberStyle = "mso-number-format:'\\0022Rp\\0022 #,##0';";
} else {
// Browser-friendly: Indonesian currency format with thousand separator
$fmtRupiah = fn($v) => 'Rp ' . number_format((float)$v, 0, ',', '.');
$fmtBrowser = fn($v) => 'Rp ' . number_format(abs((float)$v), 0, ',', '.');
$fmtExportPlain = fn($v) => number_format((float)$v, 0, '', '');
$cellNumberStyle = '';
}

// Supplier-focused report only: we use t_pi_bahan.grand_total as source for trading & non-trading PI
// Supplier-focused report only. We'll use t_pi_bahan.grand_total as source for trading & non-trading PI.

// ============== HELPER FILTER Trading/Non-Trading (GLOBAL) ==============
$tradingExprPI = "COALESCE(pib.is_trading, tpb.is_trading, FALSE)";
$nonTradingExprPI = "COALESCE(pib.is_trading, tpb.is_trading, FALSE) = FALSE";
$tradingExprPOOnly = "COALESCE(tpb.is_trading, FALSE) = TRUE";
$nonTradingExprPOOnly = "COALESCE(tpb.is_trading, FALSE) = FALSE";

// ============== HELPER tampilkan supplier yang punya pergerakan =========
$onlySupplierWithMovement = function ($q) {
$q->whereNotNull('pi.total_pi')
->orWhereNotNull('pay.bayar_po');
};

// ============== HELPER union pembayaran (GLOBAL, TANPA PERIODE) =========
$makePayUnion = function (string $mode) use ($tradingExprPOOnly, $nonTradingExprPOOnly, $tradingExprPI, $nonTradingExprPI) {
// DP via t_po_bahan_h
$dp = DB::table('t_pengeluaran_kas as pk')
->join('t_po_bahan as tpb', 'tpb.id', '=', 'pk.t_po_bahan_h_id')
->whereNotNull('pk.t_po_bahan_h_id')
->where('pk.status', 'POST')
->when($mode === 'trading', fn($q)=>$q->whereRaw($tradingExprPOOnly))
->when($mode === 'nontrading',fn($q)=>$q->whereRaw($nonTradingExprPOOnly))
->selectRaw('tpb.m_supplier_id, pk.id AS pay_id, COALESCE(pk.jumlah,0) AS bayar_po');

// direct ke PO
$directPO = DB::table('t_pengeluaran_kas as pk')
->join('t_po_bahan as tpb', 'tpb.id', '=', 'pk.t_po_bahan_id')
->whereNotNull('pk.t_po_bahan_id')
->where('pk.status', 'POST')
->when($mode === 'trading', fn($q)=>$q->whereRaw($tradingExprPOOnly))
->when($mode === 'nontrading',fn($q)=>$q->whereRaw($nonTradingExprPOOnly))
->selectRaw('tpb.m_supplier_id, pk.id AS pay_id, COALESCE(pk.jumlah,0) AS bayar_po');

// direct ke PI
$directPI = DB::table('t_pengeluaran_kas as pk')
->join('t_pi_bahan as pib', 'pib.id', '=', 'pk.t_pi_bahan_id')
->join('t_po_bahan as tpb', 'tpb.id', '=', 'pib.t_po_bahan_id')
->whereNotNull('pk.t_pi_bahan_id')
->where('pk.status', 'POST')
->when($mode === 'trading', fn($q)=>$q->whereRaw("$tradingExprPI = TRUE"))
->when($mode === 'nontrading',fn($q)=>$q->whereRaw($nonTradingExprPI))
->selectRaw('tpb.m_supplier_id, pk.id AS pay_id, COALESCE(pk.jumlah,0) AS bayar_po');

return DB::query()
->fromSub($dp->unionAll($directPO)->unionAll($directPI), 'pay_raw')
->selectRaw('pay_raw.m_supplier_id, SUM(bayar_po) AS bayar_po')
->groupBy('m_supplier_id');
};

// =================== TRADING: total PI + pembayaran (GLOBAL) ===================
$piTrading = DB::table('t_pi_bahan as pib')
->join('t_po_bahan as tpb', 'tpb.id', '=', 'pib.t_po_bahan_id')
->whereRaw("$tradingExprPI = TRUE")
->where('pib.status', 'POST')
->selectRaw('tpb.m_supplier_id, SUM(COALESCE(pib.grand_total,0)) AS total_pi')
->groupBy('tpb.m_supplier_id');

$payTradingUnion = $makePayUnion('trading');

// For supplier-level payments, use PI-only pengeluaran: payments that reference a PI
// Payments to count for supplier-level saldo:
// 1) pengeluaran linked to a PI (pk.t_pi_bahan_id IS NOT NULL)
// 2) pengeluaran linked to a PO (pk.t_po_bahan_id) but that PO has NO PI records.
$pay_from_pi = DB::table('t_pengeluaran_kas as pk')
->join('t_pi_bahan as pib', 'pib.id', '=', 'pk.t_pi_bahan_id')
->join('t_po_bahan as tpb', 'tpb.id', '=', 'pib.t_po_bahan_id')
->whereNotNull('pk.t_pi_bahan_id')
->where('pk.status', 'POST')
->when(true, fn($q) => $q->whereRaw("$tradingExprPI = TRUE"))
->selectRaw('tpb.m_supplier_id, COALESCE(pk.jumlah,0) AS bayar_po');

$pay_from_po_no_pi = DB::table('t_pengeluaran_kas as pk')
->join('t_po_bahan as tpb', 'tpb.id', '=', 'pk.t_po_bahan_id')
->whereNotNull('pk.t_po_bahan_id')
->whereNull('pk.t_pi_bahan_id')
->where('pk.status', 'POST')
->when(true, fn($q) => $q->whereRaw($tradingExprPOOnly))
->selectRaw('tpb.m_supplier_id, COALESCE(pk.jumlah,0) AS bayar_po');

$payTradingPiOnly = DB::query()
->fromSub($pay_from_pi->unionAll($pay_from_po_no_pi), 'pay_raw')
->selectRaw('pay_raw.m_supplier_id, SUM(bayar_po) AS bayar_po')
->groupBy('m_supplier_id');

$hutangTrading = DB::table('m_supplier as s')
->leftJoinSub($piTrading, 'pi', 'pi.m_supplier_id', '=', 's.id')
->leftJoinSub($payTradingPiOnly, 'pay', 'pay.m_supplier_id', '=', 's.id')
->select([
's.id',
's.nama_lengkap_supplier',
DB::raw('COALESCE(pi.total_pi,0) AS total_pi'),
DB::raw('COALESCE(pay.bayar_po,0) AS bayar_po'),
DB::raw('(COALESCE(pi.total_pi,0) - COALESCE(pay.bayar_po,0)) AS saldo_hutang'),
])
->where(function ($q) use ($onlySupplierWithMovement) { $onlySupplierWithMovement($q); })
->orderBy('s.nama_lengkap_supplier')
->get();

// Hanya tampilkan supplier yang memiliki pergerakan (total_pi != 0 OR bayar_po != 0)
$hutangTrading = $hutangTrading->filter(fn($r) => ((float)($r->total_pi ?? 0) != 0) || ((float)($r->bayar_po ?? 0) != 0))->values();

// Only keep suppliers that have movement (either total_pi or bayar_po)
$hutangTrading = $hutangTrading->filter(fn($r) => ((float)($r->total_pi ?? 0) != 0) || ((float)($r->bayar_po ?? 0) != 0))->values();

// total hutang trading: sum of positive saldo_hutang (we owe them)
$totalHutangTrading = $hutangTrading->reduce(
fn($c,$r)=> $c + (((float)($r->saldo_hutang ?? 0) > 0) ? (float)$r->saldo_hutang : 0),
0.0
);

// total piutang trading: sum of negative saldo_hutang (supplier owes us)
$totalPiutangTrading = $hutangTrading->reduce(
fn($c,$r)=> $c + (((float)($r->saldo_hutang ?? 0) < 0) ? abs((float)$r->saldo_hutang) : 0),
    0.0
    );

    // ============ NON-TRADING (CV): total PI + pembayaran (GLOBAL) ============
    $piNonTrading = DB::table('t_pi_bahan as pib')
    ->join('t_po_bahan as tpb', 'tpb.id', '=', 'pib.t_po_bahan_id')
    ->whereRaw($nonTradingExprPI)
    ->where('pib.status', 'POST')
    ->selectRaw('tpb.m_supplier_id, SUM(COALESCE(pib.grand_total,0)) AS total_pi')
    ->groupBy('tpb.m_supplier_id');

    $payNonTradingUnion = $makePayUnion('nontrading');

    $hutangNonTrading = DB::table('m_supplier as s')
    ->leftJoinSub($piNonTrading, 'pi', 'pi.m_supplier_id', '=', 's.id')
    ->leftJoinSub(
    // payments: PI-linked + PO-only-but-no-PI for non-trading
    DB::query()->fromSub(
    DB::table('t_pengeluaran_kas as pk')
    ->join('t_pi_bahan as pib', 'pib.id', '=', 'pk.t_pi_bahan_id')
    ->join('t_po_bahan as tpb', 'tpb.id', '=', 'pib.t_po_bahan_id')
    ->whereNotNull('pk.t_pi_bahan_id')
    ->where('pk.status', 'POST')
    ->when(true, fn($q) => $q->whereRaw($nonTradingExprPI))
    ->selectRaw('tpb.m_supplier_id, COALESCE(pk.jumlah,0) AS bayar_po')
    ->unionAll(
    DB::table('t_pengeluaran_kas as pk')
    ->join('t_po_bahan as tpb', 'tpb.id', '=', 'pk.t_po_bahan_id')
    ->whereNotNull('pk.t_po_bahan_id')
    ->whereNull('pk.t_pi_bahan_id')
    ->where('pk.status', 'POST')
    ->when(true, fn($q) => $q->whereRaw($nonTradingExprPOOnly))
    ->selectRaw('tpb.m_supplier_id, COALESCE(pk.jumlah,0) AS bayar_po')
    ), 'pay_raw'
    )
    ->selectRaw('pay_raw.m_supplier_id, SUM(bayar_po) AS bayar_po')
    ->groupBy('m_supplier_id'),
    'pay', 'pay.m_supplier_id', '=', 's.id')
    ->select([
    's.id',
    's.nama_lengkap_supplier',
    DB::raw('COALESCE(pi.total_pi,0) AS total_pi'),
    DB::raw('COALESCE(pay.bayar_po,0) AS bayar_po'),
    DB::raw('(COALESCE(pi.total_pi,0) - COALESCE(pay.bayar_po,0)) AS saldo_hutang'),
    ])
    ->where(function ($q) use ($onlySupplierWithMovement) { $onlySupplierWithMovement($q); })
    ->orderBy('s.nama_lengkap_supplier')
    ->get();

    // Hanya tampilkan supplier yang memiliki pergerakan (total_pi != 0 OR bayar_po != 0)
    $hutangNonTrading = $hutangNonTrading->filter(fn($r) => ((float)($r->total_pi ?? 0) != 0) || ((float)($r->bayar_po ?? 0) != 0))->values();

    // Only keep suppliers that have movement
    $hutangNonTrading = $hutangNonTrading->filter(fn($r) => ((float)($r->total_pi ?? 0) != 0) || ((float)($r->bayar_po ?? 0) != 0))->values();

    // total hutang non-trading: sum of positive saldo_hutang
    $totalHutangNonTrading = $hutangNonTrading->reduce(
    fn($c,$r)=> $c + (((float)($r->saldo_hutang ?? 0) > 0) ? (float)$r->saldo_hutang : 0),
    0.0
    );

    // total piutang non-trading: sum of negative saldo_hutang (supplier owes us)
    $totalPiutangNonTrading = $hutangNonTrading->reduce(
    fn($c,$r)=> $c + (((float)($r->saldo_hutang ?? 0) < 0) ? abs((float)$r->saldo_hutang) : 0),
        0.0
        );

        // ========================== VISIBILITY SECTION ==========================
        $showAll = $jenisPo === '';
        $showTrade = $showAll || $jenisPo === 'Hutang Piutang Supplier Trading';
        $showCV = $showAll || $jenisPo === 'Hutang Piutang Supplier CV. Tofan Asembagus';
        // show customer section when user requests customer report
        $showCustomer = $showAll || $jenisPo === 'Hutang Piutang Customer';
        @endphp

        @php
        // =========== PI breakdown: Grand Total (from pib.grand_total) minus pengeluaran_kas per PI ==========
        // Strict PI-only matching: count only pengeluaran explicitly linked to this PI
        // via pk.t_pi_bahan_id = pib.id. This removes all PO/NAME-based matching.
        $piBreakdown = DB::table('t_pi_bahan as pib')
        ->join('t_po_bahan as tpb', 'tpb.id', '=', 'pib.t_po_bahan_id')
        ->where('pib.status', 'POST')
        ->selectRaw("pib.id AS pi_id, pib.no_pi, tpb.no_po, tpb.m_supplier_id, pib.t_po_bahan_id AS po_id, COALESCE(pib.grand_total,0) AS grand_total,
        (
        SELECT COALESCE(SUM(pk.jumlah),0)
        FROM t_pengeluaran_kas pk
        WHERE pk.status = 'POST'
        AND pk.t_pi_bahan_id = pib.id
        ) AS total_pengeluaran")
        ->orderBy('tpb.m_supplier_id')
        ->get();

        // Attach supplier name for easier rendering
        $supplierMap = DB::table('m_supplier')->select('id','nama_lengkap_supplier')->get()->keyBy('id');
        @endphp

        @php
        // ====================== CUSTOMER (RECEIVABLES) SECTION - COMBINED ======================
        // Combine trading and non-trading into a single Piutang Customer list and
        // place it above the PI detail section.

        // SI totals grouped by customer (ALL statuses: trading & non-trading)
        $siAll = DB::table('t_sales_invoice as si')
        ->where('si.status','POST')
        ->selectRaw('si.m_customer_id, SUM(COALESCE(si.grand_total,0)) AS total_si')
        ->groupBy('si.m_customer_id');

        // payments: penerimaan linked to SI
        $pay_from_si = DB::table('t_penerimaan_kas as pk')
        ->join('t_sales_invoice as si', 'si.id', '=', 'pk.t_sales_invoice_id')
        ->whereNotNull('pk.t_sales_invoice_id')
        ->where('pk.status','POST')
        ->selectRaw('si.m_customer_id, COALESCE(pk.jumlah,0) AS bayar');

        // payments: penerimaan linked to SO but not to SI (SO-only receipts)
        $pay_from_so_no_si = DB::table('t_penerimaan_kas as pk')
        ->join('t_sales_order as so', 'so.id', '=', 'pk.t_sales_order_id')
        ->whereNotNull('pk.t_sales_order_id')
        ->whereNull('pk.t_sales_invoice_id')
        ->where('pk.status','POST')
        ->selectRaw('so.m_customer_id, COALESCE(pk.jumlah,0) AS bayar');

        $payAll = DB::query()
        ->fromSub($pay_from_si->unionAll($pay_from_so_no_si), 'pay_raw')
        ->selectRaw('pay_raw.m_customer_id, SUM(bayar) AS bayar')
        ->groupBy('m_customer_id');

        $piutangAll = DB::table('m_customer as c')
        ->leftJoinSub($siAll, 'si_tot', 'si_tot.m_customer_id', '=', 'c.id')
        ->leftJoinSub($payAll, 'pay', 'pay.m_customer_id', '=', 'c.id')
        ->select([
        'c.id', 'c.nama_lengkap_customer',
        DB::raw('COALESCE(si_tot.total_si,0) AS total_si'),
        DB::raw('COALESCE(pay.bayar,0) AS bayar'),
        DB::raw('(COALESCE(si_tot.total_si,0) - COALESCE(pay.bayar,0)) AS saldo_piutang'),
        ])
        ->where(function($q){ $q->whereNotNull('si_tot.total_si')->orWhereNotNull('pay.bayar'); })
        ->orderBy('c.nama_lengkap_customer')
        ->get();

        // filter out zero movements
        $piutangAll = $piutangAll->filter(fn($r)=> ((float)($r->total_si ?? 0) != 0) || ((float)($r->bayar ?? 0) != 0))->values();

        // Separate hutang dan piutang like supplier section does
        // Hutang ke customer = positive saldo_piutang (we owe them)
        $totalHutangToCustomer = $piutangAll->reduce(fn($c,$r)=> $c + (((float)($r->saldo_piutang ?? 0) > 0) ? (float)$r->saldo_piutang : 0), 0.0);

        // Piutang dari customer = negative saldo_piutang (they owe us)
        $totalPiutangFromCustomer = $piutangAll->reduce(fn($c,$r)=> $c + (((float)($r->saldo_piutang ?? 0) < 0) ? abs((float)$r->saldo_piutang) : 0), 0.0);

            // Combined total for backward compatibility
            $totalPiutangAll = $totalHutangToCustomer;

            // debug helpers: ?debug_customer=Name (reuse existing debug variables if present)
            // Note: $customerMap is defined above.
            @endphp


            @php
            // -- TEMP DEBUG: when ?debug_supplier=Name is provided, list exact pengeluaran_kas rows
            // that the breakdown query would sum for each PI of that supplier. This helps
            // identify why a supplier (e.g., 'Opek') still shows non-zero pengeluaran.
            $debugSupplierName = trim((string)($req->get('debug_supplier') ?? ''));
            $debugMatches = [];
            if ($debugSupplierName !== '') {
            $dbgSupplier = DB::table('m_supplier')->where('nama_lengkap_supplier', $debugSupplierName)->first();
            if ($dbgSupplier) {
            foreach ($piBreakdown as $b) {
            // match by supplier id (no name) as requested
            if (($b->m_supplier_id ?? null) != $dbgSupplier->id) continue;

            // Find pk rows that reference PI/PO whose m_supplier_id equals the debug supplier id
            $matched = DB::table('t_pengeluaran_kas as pk')
            ->selectRaw('pk.id, pk.tanggal, pk.jumlah, pk.catatan AS keterangan, pk.t_pi_bahan_id, pk.t_po_bahan_id, pk.t_po_bahan_h_id')
            ->where('pk.status','POST')
            ->where(function($q) use ($dbgSupplier) {
            $q->whereRaw('(pk.t_pi_bahan_id IS NOT NULL AND (SELECT t2.m_supplier_id FROM t_pi_bahan p2 JOIN t_po_bahan t2 ON t2.id = p2.t_po_bahan_id WHERE p2.id = pk.t_pi_bahan_id) = ?)', [$dbgSupplier->id])
            ->orWhereRaw('(pk.t_po_bahan_id IS NOT NULL AND (SELECT m_supplier_id FROM t_po_bahan WHERE id = pk.t_po_bahan_id) = ?)', [$dbgSupplier->id])
            ->orWhereRaw('(pk.t_po_bahan_h_id IS NOT NULL AND (SELECT m_supplier_id FROM t_po_bahan WHERE id = pk.t_po_bahan_h_id) = ?)', [$dbgSupplier->id]);
            })
            ->orderBy('pk.tanggal')
            ->get();

            $debugMatches[$b->pi_id] = ['pi' => $b, 'pk_rows' => $matched];
            }
            }
            }
            @endphp

            @php
            // Focused PI debug: when ?debug_pi=PI-xxxx is provided, show all pk rows that
            // reference that PI (by t_pi_bahan_id OR by referencing the same PO id), and
            // display the resolved supplier ids for each pk reference so we can trace
            // why a pengeluaran was attributed to the PI's supplier.
            $debugPiNo = trim((string)($req->get('debug_pi') ?? ''));
            $debugPiMatches = [];
            if ($debugPiNo !== '') {
            $pis = DB::table('t_pi_bahan as pib')->join('t_po_bahan as tpb','tpb.id','=','pib.t_po_bahan_id')
            ->where('pib.no_pi', $debugPiNo)->selectRaw('pib.id AS pi_id, pib.no_pi, pib.t_po_bahan_id AS po_id, tpb.m_supplier_id AS supplier_id')->get();
            foreach ($pis as $pi) {
            $rows = DB::table('t_pengeluaran_kas as pk')
            ->selectRaw('pk.id, pk.no_pengeluaran, pk.tanggal, pk.jumlah, pk.catatan AS keterangan, pk.t_pi_bahan_id, pk.t_po_bahan_id, pk.t_po_bahan_h_id,
            (CASE WHEN pk.t_pi_bahan_id IS NOT NULL THEN (SELECT t2.m_supplier_id FROM t_pi_bahan p2 JOIN t_po_bahan t2 ON t2.id = p2.t_po_bahan_id WHERE p2.id = pk.t_pi_bahan_id) ELSE NULL END) AS resolved_from_pi_supplier_id,
            (CASE WHEN pk.t_po_bahan_id IS NOT NULL THEN (SELECT m_supplier_id FROM t_po_bahan WHERE id = pk.t_po_bahan_id) ELSE NULL END) AS resolved_from_po_supplier_id,
            (CASE WHEN pk.t_po_bahan_h_id IS NOT NULL THEN (SELECT m_supplier_id FROM t_po_bahan WHERE id = pk.t_po_bahan_h_id) ELSE NULL END) AS resolved_from_poh_supplier_id')
            ->where('pk.status','POST')
            ->where(function($q) use ($pi) {
            $q->where('pk.t_pi_bahan_id', $pi->pi_id)
            ->orWhere('pk.t_po_bahan_id', $pi->po_id)
            ->orWhere('pk.t_po_bahan_h_id', $pi->po_id);
            })
            ->orderBy('pk.tanggal')
            ->get();

            $debugPiMatches[$pi->pi_id] = ['pi' => $pi, 'pk_rows' => $rows];
            }
            }
            @endphp

            <br>

            {{-- ==================== HUTANG SUPPLIER TRADING =================== --}}
            @php
            // Check if there's any actual data with non-zero amounts
            $totalNettoTrading = $totalHutangTrading - $totalPiutangTrading;
            $hasTradeData = $hutangTrading->isNotEmpty() && $totalNettoTrading != 0;
            @endphp
            @if($showTrade && $hasTradeData)
            <div>
                <h3 style="text-align:center; font-weight:bold;">Laporan Hutang Piutang Supplier Trading</h3>
            </div>
            <table style="border:1px solid black; border-collapse:collapse; width:100%; font-size:11px;">
                <thead>
                    <tr style="background:#f2f2f2; font-weight:bold; text-align:center;">
                        <td style="border:1px solid black; padding:5px;">No.</td>
                        <td style="border:1px solid black; padding:5px;">Supplier</td>
                        <td style="border:1px solid black; padding:5px;">Rp</td>
                    </tr>
                </thead>
                <tbody>
                    @php $no = 1; @endphp
                    @foreach($hutangTrading as $row)
                    @php
                    // Display saldo_hutang (total_pi - bayar_po). If saldo is zero skip.
                    $amount = (float)($row->saldo_hutang ?? $row->total_pi ?? 0);
                    if ($amount == 0) { continue; }
                    // Display absolute value with appropriate formatting
                    $displayValue = $isExcelExport ? $fmtExportPlain(abs($amount)) : $fmtBrowser(abs($amount));
                    // Color based on sign of original amount: positive (hutang) = red, negative (piutang) = black
                    $cellColor = ($amount > 0) ? 'red' : 'black';
                    @endphp
                    <tr>
                        <td style="border:1px solid black; padding:5px; text-align:center;">{{ $no++ }}</td>
                        <td style="border:1px solid black; padding:5px;">{{ $row->nama_lengkap_supplier }}</td>
                        <td style="border:1px solid black; padding:5px; text-align:right; {{ $cellNumberStyle }} color: {{ $cellColor }} !important;">{{ $displayValue }}</td>
                    </tr>
                    @endforeach

                    <tr style="background:#f2f2f2; font-weight:bold;">
                        <td colspan="2" style="border:1px solid black; padding:5px;">TOTAL</td>
                        @php
                        $totalNettoAbsDisplay = $isExcelExport ? $fmtExportPlain(abs($totalNettoTrading)) : $fmtBrowser(abs($totalNettoTrading));
                        $totalNettoColor = $totalNettoTrading > 0 ? 'red' : 'black';
                        @endphp
                        <td style="border:1px solid black; padding:5px; text-align:right; color: {{ $totalNettoColor }} !important; font-weight:bold;">{{ $totalNettoAbsDisplay }}</td>
                    </tr>
                </tbody>
            </table>
            <br>
            @else
            @if($showTrade)
            <div style="text-align:center; padding:20px; font-size:12px; color:#666;">
                <strong>Laporan Hutang Piutang Supplier Trading</strong><br>
                (Tidak ada data)
            </div>
            @endif
            @endif
            @php
            // Check if there's any actual data with non-zero amounts for non-trading
            $totalNettoNonTrading = $totalHutangNonTrading - $totalPiutangNonTrading;
            $hasNonTradeData = $hutangNonTrading->isNotEmpty() && $totalNettoNonTrading != 0;
            @endphp
            @if($showCV && $hasNonTradeData)
            <div>
                <h3 style="text-align:center; font-weight:bold;">Laporan Hutang Piutang Supplier CV. Tofan Asembagus</h3>
            </div>
            <table style="border:1px solid black; border-collapse:collapse; width:100%; font-size:11px;">
                <thead>
                    <tr style="background:#f2f2f2; font-weight:bold; text-align:center;">
                        <td style="border:1px solid black; padding:5px;">No.</td>
                        <td style="border:1px solid black; padding:5px;">Supplier</td>
                        <td style="border:1px solid black; padding:5px;">Rp</td>
                    </tr>
                </thead>
                <tbody>
                    @php $no = 1; @endphp
                    @foreach($hutangNonTrading as $row)
                    @php
                    // Display saldo_hutang (total_pi - bayar_po). If saldo is zero skip.
                    $amount = (float)($row->saldo_hutang ?? $row->total_pi ?? 0);
                    if ($amount == 0) { continue; }
                    // Display absolute value with appropriate formatting
                    $displayValue = $isExcelExport ? $fmtExportPlain(abs($amount)) : $fmtBrowser(abs($amount));
                    // Color based on sign of original amount: positive (hutang) = red, negative (piutang) = black
                    $cellColor = ($amount > 0) ? 'red' : 'black';
                    @endphp
                    <tr>
                        <td style="border:1px solid black; padding:5px; text-align:center;">{{ $no++ }}</td>
                        <td style="border:1px solid black; padding:5px;">{{ $row->nama_lengkap_supplier }}</td>
                        <td style="border:1px solid black; padding:5px; text-align:right; {{ $cellNumberStyle }} color: {{ $cellColor }} !important;">{{ $displayValue }}</td>
                    </tr>
                    @endforeach

                    <tr style="background:#f2f2f2; font-weight:bold;">
                        <td colspan="2" style="border:1px solid black; padding:5px;">TOTAL</td>
                        @php
                        $totalNettoAbsDisplay = $isExcelExport ? $fmtExportPlain(abs($totalNettoNonTrading)) : $fmtBrowser(abs($totalNettoNonTrading));
                        $totalNettoColor = $totalNettoNonTrading > 0 ? 'red' : 'black';
                        @endphp
                        <td style="border:1px solid black; padding:5px; text-align:right; color: {{ $totalNettoColor }} !important; font-weight:bold;">{{ $totalNettoAbsDisplay }}</td>
                    </tr>
                </tbody>
            </table>
            @else
            @if($showCV)
            <div style="text-align:center; padding:20px; font-size:12px; color:#666;">
                <strong>Laporan Hutang Piutang Supplier CV. Tofan Asembagus</strong><br>
                (Tidak ada data)
            </div>
            @endif
            @endif

            {{-- ==================== PIUTANG CUSTOMER (COMBINED) - render above PI detail =================== --}}
            @php
            // Check if there's any actual data with non-zero amounts for customer
            $totalNettoCustomer = $totalHutangToCustomer - $totalPiutangFromCustomer;
            $hasCustomerData = $piutangAll->isNotEmpty() && $totalNettoCustomer != 0;
            @endphp
            @if($showCustomer && $hasCustomerData)
            <div>
                <h3 style="text-align:center; font-weight:bold;">Laporan Piutang Customer</h3>
            </div>
            <table style="border:1px solid black; border-collapse:collapse; width:100%; font-size:11px;">
                <thead>
                    <tr style="background:#f2f2f2; font-weight:bold; text-align:center;">
                        <td style="border:1px solid black; padding:5px;">No.</td>
                        <td style="border:1px solid black; padding:5px;">Customer</td>
                        <td style="border:1px solid black; padding:5px;">Rp</td>
                    </tr>
                </thead>
                <tbody>
                    @php $no = 1; @endphp
                    @foreach($piutangAll as $row)
                    @php
                    $amount = (float)($row->saldo_piutang ?? $row->total_si ?? 0);
                    if ($amount == 0) { continue; }
                    // Display absolute value with appropriate formatting
                    $displayValue = $isExcelExport ? $fmtExportPlain(abs($amount)) : $fmtBrowser(abs($amount));
                    // Color based on sign of original amount: positive (hutang) = red, negative (piutang) = black
                    $cellColor = ($amount < 0) ? 'red' : 'black' ;
                        @endphp
                        <tr>
                        <td style="border:1px solid black; padding:5px; text-align:center;">{{ $no++ }}</td>
                        <td style="border:1px solid black; padding:5px;">{{ $row->nama_lengkap_customer }}</td>
                        <td style="border:1px solid black; padding:5px; text-align:right; {{ $cellNumberStyle }} color: {{ $cellColor }} !important;">{{ $displayValue }}</td>
                        </tr>
                        @endforeach

                        @php
                        $totalNettoCustomerAbsDisplay = $isExcelExport ? $fmtExportPlain(abs($totalNettoCustomer)) : $fmtBrowser(abs($totalNettoCustomer));
                        // Hutang ke customer (positive) = merah, Piutang dari customer (negative/zero) = hitam
                        $totalNettoCustomerColor = $totalNettoCustomer < 0 ? 'red' : 'black' ;
                            @endphp
                            <tr style="background:#f2f2f2; font-weight:bold;">
                            <td colspan="2" style="border:1px solid black; padding:5px;">TOTAL</td>
                            <td style="border:1px solid black; padding:5px; text-align:right; color: {{ $totalNettoCustomerColor }} !important; font-weight:bold;">{{ $totalNettoCustomerAbsDisplay }}</td>
                            </tr>
                </tbody>
            </table>
            <br>
            @else
            @if($showCustomer)
            <div style="text-align:center; padding:20px; font-size:12px; color:#666;">
                <strong>Laporan Piutang Customer</strong><br>
                (Tidak ada data)
            </div>
            @endif
            @endif
            <!-- <br>
<div><h3 style="text-align:center; font-weight:bold;">Detail PI vs Pengeluaran Kas (Grand Total - Jumlah)</h3></div>
<table style="border:1px solid black; border-collapse:collapse; width:100%; font-size:11px;">
  <thead>
    <tr style="background:#f2f2f2; font-weight:bold; text-align:center;">
      <td style="border:1px solid black; padding:5px;">No.</td>
      <td style="border:1px solid black; padding:5px;">No PO</td>
      <td style="border:1px solid black; padding:5px;">No PI</td>
      <td style="border:1px solid black; padding:5px;">Supplier</td>
      <td style="border:1px solid black; padding:5px;">Grand Total</td>
      <td style="border:1px solid black; padding:5px;">Jumlah Pengeluaran</td>
      <td style="border:1px solid black; padding:5px;">Saldo</td>
    </tr>
  </thead>
  <tbody>
      @php $i = 1; @endphp
    @foreach($piBreakdown as $b)
      @php
        $supplierName = $supplierMap[$b->m_supplier_id]->nama_lengkap_supplier ?? 'N/A';
        $gt = (float)($b->grand_total ?? 0);
        $paid = (float)($b->total_pengeluaran ?? 0);
        $saldo = $gt - $paid;
        if ($saldo == 0) { continue; }
        $saldoDisplay = $fmtRupiah(abs($saldo));
        $gtDisplay = $fmtRupiah($gt);
        $paidDisplay = $fmtRupiah($paid);
      @endphp
      <tr>
        <td style="border:1px solid black; padding:5px; text-align:center;">{{ $i++ }}</td>
        <td style="border:1px solid black; padding:5px;">{{ $b->no_po }}</td>
        <td style="border:1px solid black; padding:5px;">{{ $b->no_pi }}</td>
        <td style="border:1px solid black; padding:5px;">{{ $supplierName }}</td>
          <td style="border:1px solid black; padding:5px; text-align:right; {{ $cellNumberStyle }}">{{ $gtDisplay }}</td>
          <td style="border:1px solid black; padding:5px; text-align:right; {{ $cellNumberStyle }}">{{ $paidDisplay }}</td>
        @php
          // For PI detail: negative saldo means supplier owes us (Piutang) -> red
          $isPiutangDetail = $saldo < 0;
        @endphp
  <td style="border:1px solid black; padding:5px; text-align:right; {{ $cellNumberStyle }} color: {{ $isPiutangDetail ? 'red !important' : 'black !important' }};">{{ $saldoDisplay }}</td>
      </tr>
    @endforeach
  </tbody>
</table> -->

            @php
            // Customer calculations were moved earlier and combined into $piutangAll / $totalPiutangAll.
            // The rest below focuses on SI-level breakdown and debugging helpers.

            // SI breakdown: per-SI grand_total minus penerimaan_kas linked to that SI (strict SI matching)
            $siBreakdown = DB::table('t_sales_invoice as si')
            ->where('si.status','POST')
            ->selectRaw("si.id AS si_id, si.no_si, si.m_customer_id, COALESCE(si.grand_total,0) AS grand_total,
            (
            SELECT COALESCE(SUM(pk.jumlah),0)
            FROM t_penerimaan_kas pk
            WHERE pk.status = 'POST'
            AND pk.t_sales_invoice_id = si.id
            ) AS total_penerimaan")
            ->orderBy('si.m_customer_id')
            ->get();

            // map customers
            $customerMap = DB::table('m_customer')->select('id','nama_lengkap_customer')->get()->keyBy('id');

            // debug helpers: ?debug_customer=Name
            $debugCustomerName = trim((string)($req->get('debug_customer') ?? ''));
            $debugCustomerMatches = [];
            if ($debugCustomerName !== '') {
            $dbg = DB::table('m_customer')->where('nama_lengkap_customer', $debugCustomerName)->first();
            if ($dbg) {
            foreach ($siBreakdown as $s) {
            if (($s->m_customer_id ?? null) != $dbg->id) continue;
            $matched = DB::table('t_penerimaan_kas as pk')
            ->selectRaw('pk.id, pk.tgl AS tanggal, pk.jumlah, pk.catatan AS keterangan, pk.t_sales_invoice_id, pk.t_sales_order_id')
            ->where('pk.status','POST')
            ->where(function($q) use ($dbg) {
            $q->whereRaw('(pk.t_sales_invoice_id IS NOT NULL AND (SELECT m_customer_id FROM t_sales_invoice WHERE id = pk.t_sales_invoice_id) = ?)', [$dbg->id])
            ->orWhereRaw('(pk.t_sales_order_id IS NOT NULL AND (SELECT m_customer_id FROM t_sales_order WHERE id = pk.t_sales_order_id) = ?)', [$dbg->id]);
            })
            ->orderBy('pk.tgl')
            ->get();

            $debugCustomerMatches[$s->si_id] = ['si' => $s, 'pk_rows' => $matched];
            }
            }
            }

            // focused SI debug: ?debug_si=SI-xxxx
            $debugSiNo = trim((string)($req->get('debug_si') ?? ''));
            $debugSiMatches = [];
            if ($debugSiNo !== '') {
            $sis = DB::table('t_sales_invoice as si')->where('si.no_si', $debugSiNo)
            ->selectRaw('si.id AS si_id, si.no_si, si.m_customer_id')->get();
            foreach ($sis as $si) {
            $rows = DB::table('t_penerimaan_kas as pk')
            ->selectRaw('pk.id, pk.no_penerimaan, pk.tgl, pk.jumlah, pk.catatan AS keterangan, pk.t_sales_invoice_id, pk.t_sales_order_id,
            (CASE WHEN pk.t_sales_invoice_id IS NOT NULL THEN (SELECT m_customer_id FROM t_sales_invoice WHERE id = pk.t_sales_invoice_id) ELSE NULL END) AS resolved_from_si_customer_id,
            (CASE WHEN pk.t_sales_order_id IS NOT NULL THEN (SELECT m_customer_id FROM t_sales_order WHERE id = pk.t_sales_order_id) ELSE NULL END) AS resolved_from_so_customer_id')
            ->where('pk.status','POST')
            ->where(function($q) use ($si) {
            $q->where('pk.t_sales_invoice_id', $si->si_id)
            ->orWhere('pk.t_sales_order_id', DB::raw($si->si_id));
            })
            ->orderBy('pk.tgl')
            ->get();

            $debugSiMatches[$si->si_id] = ['si' => $si, 'pk_rows' => $rows];
            }
            }

            // recent penerimaan kas list (status=POST)
            $penerimaanRows = DB::table('t_penerimaan_kas as pk')
            ->leftJoin('t_sales_invoice as si', 'si.id', '=', 'pk.t_sales_invoice_id')
            ->leftJoin('t_sales_order as so', 'so.id', '=', 'pk.t_sales_order_id')
            ->selectRaw('pk.id, pk.no_penerimaan, pk.tgl, pk.jumlah, pk.catatan AS keterangan, pk.status, pk.t_sales_invoice_id, si.no_si, pk.t_sales_order_id, so.no_so,
            (CASE WHEN pk.t_sales_invoice_id IS NOT NULL THEN (SELECT m_customer_id FROM t_sales_invoice WHERE id = pk.t_sales_invoice_id)
            WHEN pk.t_sales_order_id IS NOT NULL THEN (SELECT m_customer_id FROM t_sales_order WHERE id = pk.t_sales_order_id)
            ELSE NULL END) AS resolved_m_customer_id,
            (CASE WHEN pk.t_sales_invoice_id IS NOT NULL THEN (SELECT nama_lengkap_customer FROM m_customer WHERE id = (SELECT m_customer_id FROM t_sales_invoice WHERE id = pk.t_sales_invoice_id))
            WHEN pk.t_sales_order_id IS NOT NULL THEN (SELECT nama_lengkap_customer FROM m_customer WHERE id = (SELECT m_customer_id FROM t_sales_order WHERE id = pk.t_sales_order_id))
            ELSE NULL END) AS resolved_customer_name')
            ->where('pk.status', 'POST')
            ->orderByDesc('pk.tgl')
            ->limit(200)
            ->get();
            @endphp



            <!-- @if($showCustomer && !empty($debugCustomerName) && ($dbg ?? null))
  <br>
  <div><h3 style="text-align:center; font-weight:bold;">DEBUG SUMMARY for Customer: {{ $dbg->nama_lengkap_customer }} (id={{ $dbg->id }})</h3></div>
  <table style="border:1px solid black; border-collapse:collapse; width:60%; font-size:11px; margin:auto;">
    <thead>
      <tr style="background:#f2f2f2; font-weight:bold; text-align:center;">
        <td style="border:1px solid black; padding:5px;">Scope</td>
        <td style="border:1px solid black; padding:5px;">Total SI</td>
        <td style="border:1px solid black; padding:5px;">Bayar (from SI-linked PK)</td>
        <td style="border:1px solid black; padding:5px;">Saldo</td>
      </tr>
    </thead>
    <tbody>
      @php $sup = $piutangAll->firstWhere('id', $dbg->id); @endphp
      <tr>
        <td style="border:1px solid black; padding:5px;">All</td>
        <td style="border:1px solid black; padding:5px; text-align:right;">{{ $fmtRupiah($sup->total_si ?? 0) }}</td>
        <td style="border:1px solid black; padding:5px; text-align:right;">{{ $fmtRupiah($sup->bayar ?? 0) }}</td>
        <td style="border:1px solid black; padding:5px; text-align:right;">{{ $fmtRupiah($sup->saldo_piutang ?? 0) }}</td>
      </tr>
    </tbody>
  </table>

  <br>
  <div style="width:90%; margin:auto;"><strong>SI-level breakdown for this customer (SI id, no_si, grand_total, total_penerimaan, saldo)</strong></div>
  <table style="border:1px solid black; border-collapse:collapse; width:90%; font-size:11px; margin:auto;">
    <thead>
      <tr style="background:#f2f2f2; font-weight:bold; text-align:center;">
        <td style="border:1px solid black; padding:5px;">SI id</td>
        <td style="border:1px solid black; padding:5px;">No SI</td>
        <td style="border:1px solid black; padding:5px;">Grand Total</td>
        <td style="border:1px solid black; padding:5px;">Jumlah Penerimaan</td>
        <td style="border:1px solid black; padding:5px;">Saldo</td>
      </tr>
    </thead>
    <tbody>
      @foreach(collect($siBreakdown)->filter(fn($s)=>($s->m_customer_id ?? null) == $dbg->id) as $pp)
        @php
          $gt = (float)($pp->grand_total ?? 0);
          $paid = (float)($pp->total_penerimaan ?? 0);
          $saldo = $gt - $paid;
        @endphp
        <tr>
          <td style="border:1px solid black; padding:5px; text-align:center;">{{ $pp->si_id }}</td>
          <td style="border:1px solid black; padding:5px;">{{ $pp->no_si }}</td>
          <td style="border:1px solid black; padding:5px; text-align:right;">{{ $fmtRupiah($gt) }}</td>
          <td style="border:1px solid black; padding:5px; text-align:right;">{{ $fmtRupiah($paid) }}</td>
          <td style="border:1px solid black; padding:5px; text-align:right;">{{ $fmtRupiah($saldo) }}</td>
        </tr>
      @endforeach
    </tbody>
  </table>
@endif

@if($showCustomer && !empty($debugSiMatches))
  <br>
  <div><h3 style="text-align:center; font-weight:bold;">DEBUG: Penerimaan terkait SI (requested via ?debug_si=...)</h3></div>
  @foreach($debugSiMatches as $dbg)
    @php $si = $dbg['si']; $rows = $dbg['pk_rows']; $expectedCustomer = $customerMap[$si->m_customer_id]->nama_lengkap_customer ?? $si->m_customer_id; @endphp
    <div style="margin-bottom:10px;"><strong>SI:</strong> {{ $si->no_si }} (si_id={{ $si->si_id }}) &nbsp; <strong>Expected Customer:</strong> {{ $expectedCustomer }}</div>
    <table style="border:1px solid black; border-collapse:collapse; width:100%; font-size:11px; margin-bottom:20px;">
      <thead>
        <tr style="background:#f2f2f2; font-weight:bold; text-align:center;">
          <td style="border:1px solid black; padding:5px;">pk.id</td>
          <td style="border:1px solid black; padding:5px;">No Penerimaan</td>
          <td style="border:1px solid black; padding:5px;">Tgl</td>
          <td style="border:1px solid black; padding:5px;">Jumlah</td>
          <td style="border:1px solid black; padding:5px;">Catatan</td>
          <td style="border:1px solid black; padding:5px;">t_sales_invoice_id</td>
          <td style="border:1px solid black; padding:5px;">t_sales_order_id</td>
          <td style="border:1px solid black; padding:5px;">resolved_from_si_customer_id</td>
          <td style="border:1px solid black; padding:5px;">resolved_from_so_customer_id</td>
        </tr>
      </thead>
      <tbody>
        @foreach($rows as $r)
          <tr>
            <td style="border:1px solid black; padding:5px; text-align:center;">{{ $r->id }}</td>
            <td style="border:1px solid black; padding:5px;">{{ $r->no_penerimaan ?? '' }}</td>
            <td style="border:1px solid black; padding:5px;">{{ $r->tgl ?? '' }}</td>
            <td style="border:1px solid black; padding:5px; text-align:right;">{{ $fmtRupiah($r->jumlah ?? 0) }}</td>
            <td style="border:1px solid black; padding:5px;">{{ $r->keterangan ?? '' }}</td>
            <td style="border:1px solid black; padding:5px; text-align:center;">{{ $r->t_sales_invoice_id ?? '-' }}</td>
            <td style="border:1px solid black; padding:5px; text-align:center;">{{ $r->t_sales_order_id ?? '-' }}</td>
            <td style="border:1px solid black; padding:5px; text-align:center;">{{ $r->resolved_from_si_customer_id ?? '-' }}</td>
            <td style="border:1px solid black; padding:5px; text-align:center;">{{ $r->resolved_from_so_customer_id ?? '-' }}</td>
          </tr>
        @endforeach
      </tbody>
    </table>
  @endforeach
@endif

@if($showCustomer)
  <br>
  <div><h3 style="text-align:center; font-weight:bold;">Daftar Penerimaan Kas (Recent, status=POST)</h3></div>
  <table style="border:1px solid black; border-collapse:collapse; width:100%; font-size:11px;">
  <thead>
    <tr style="background:#f2f2f2; font-weight:bold; text-align:center;">
      <td style="border:1px solid black; padding:5px;">No.</td>
      <td style="border:1px solid black; padding:5px;">No Penerimaan</td>
      <td style="border:1px solid black; padding:5px;">Tgl</td>
      <td style="border:1px solid black; padding:5px;">No SI</td>
      <td style="border:1px solid black; padding:5px;">No SO</td>
      <td style="border:1px solid black; padding:5px;">Jumlah</td>
      <td style="border:1px solid black; padding:5px;">Nama Customer</td>
      <td style="border:1px solid black; padding:5px;">Catatan</td>
      <td style="border:1px solid black; padding:5px;">Status</td>
    </tr>
  </thead>
  <tbody>
    @php $nrow = 1; @endphp
    @foreach($penerimaanRows as $p)
        <tr>
          <td style="border:1px solid black; padding:5px; text-align:center;">{{ $nrow++ }}</td>
          <td style="border:1px solid black; padding:5px;">{{ $p->no_penerimaan }}</td>
          <td style="border:1px solid black; padding:5px;">{{ $p->tgl }}</td>
        <td style="border:1px solid black; padding:5px;">{{ $p->no_si ?? '-' }}</td>
        <td style="border:1px solid black; padding:5px;">{{ $p->no_so ?? '-' }}</td>
        @php $penerimaanDisplay = $isExcelExport ? $fmtExportPlain($p->jumlah ?? 0) : $fmtBrowser($p->jumlah ?? 0); @endphp
        <td style="border:1px solid black; padding:5px; text-align:right; {{ $cellNumberStyle }}">{{ $penerimaanDisplay }}</td>
        <td style="border:1px solid black; padding:5px;">{{ $p->resolved_customer_name ?? '-' }}</td>
        <td style="border:1px solid black; padding:5px;">{{ $p->keterangan ?? '' }}</td>
        <td style="border:1px solid black; padding:5px; text-align:center;">{{ $p->status }}</td>
      </tr>
    @endforeach
  </tbody>
</table> -->
            @endif


            @php
            // ============ DAFTAR PENGELUARAN KAS (DEBUG / REFERENSI) ============
            $pengeluaranRows = DB::table('t_pengeluaran_kas as pk')
            ->leftJoin('t_po_bahan as tpo', 'tpo.id', '=', 'pk.t_po_bahan_id')
            ->leftJoin('t_pi_bahan as pib', 'pib.id', '=', 'pk.t_pi_bahan_id')
            ->selectRaw('pk.id, pk.no_pengeluaran, pk.tgl, pk.jumlah, pk.catatan AS keterangan, pk.status, pk.t_po_bahan_id, tpo.no_po, pk.t_pi_bahan_id, pib.no_pi, pk.t_po_bahan_h_id, pk.is_dp, pk.tipe,
            (CASE
            WHEN pk.t_po_bahan_id IS NOT NULL THEN (SELECT m_supplier_id FROM t_po_bahan WHERE id = pk.t_po_bahan_id)
            WHEN pk.t_po_bahan_h_id IS NOT NULL THEN (SELECT m_supplier_id FROM t_po_bahan WHERE id = pk.t_po_bahan_h_id)
            WHEN pk.t_pi_bahan_id IS NOT NULL THEN (SELECT t2.m_supplier_id FROM t_pi_bahan p2 JOIN t_po_bahan t2 ON t2.id = p2.t_po_bahan_id WHERE p2.id = pk.t_pi_bahan_id)
            ELSE NULL
            END) AS resolved_m_supplier_id,
            (CASE
            WHEN pk.t_po_bahan_id IS NOT NULL THEN (SELECT nama_lengkap_supplier FROM m_supplier WHERE id = (SELECT m_supplier_id FROM t_po_bahan WHERE id = pk.t_po_bahan_id))
            WHEN pk.t_po_bahan_h_id IS NOT NULL THEN (SELECT nama_lengkap_supplier FROM m_supplier WHERE id = (SELECT m_supplier_id FROM t_po_bahan WHERE id = pk.t_po_bahan_h_id))
            WHEN pk.t_pi_bahan_id IS NOT NULL THEN (SELECT ms2.nama_lengkap_supplier FROM t_pi_bahan p2 JOIN t_po_bahan t2 ON t2.id = p2.t_po_bahan_id JOIN m_supplier ms2 ON ms2.id = t2.m_supplier_id WHERE p2.id = pk.t_pi_bahan_id)
            ELSE NULL
            END) AS resolved_supplier_name')
            ->where('pk.status', 'POST')
            ->orderByDesc('pk.tgl')
            ->limit(200)
            ->get();
            @endphp

            <!-- @if(!empty($debugPiMatches))
    <br>
    <div><h3 style="text-align:center; font-weight:bold;">DEBUG: Pengeluaran terkait PI (requested via ?debug_pi=...)</h3></div>
    @foreach($debugPiMatches as $dbg)
      @php $pi = $dbg['pi']; $rows = $dbg['pk_rows']; $expectedSupplier = $supplierMap[$pi->supplier_id]->nama_lengkap_supplier ?? $pi->supplier_id; @endphp
      <div style="margin-bottom:10px;"><strong>PI:</strong> {{ $pi->no_pi }} (pi_id={{ $pi->pi_id }}) &nbsp; <strong>PO id:</strong> {{ $pi->po_id }} &nbsp; <strong>Expected Supplier:</strong> {{ $expectedSupplier }}</div>
      <table style="border:1px solid black; border-collapse:collapse; width:100%; font-size:11px; margin-bottom:20px;">
        <thead>
          <tr style="background:#f2f2f2; font-weight:bold; text-align:center;">
            <td style="border:1px solid black; padding:5px;">pk.id</td>
            <td style="border:1px solid black; padding:5px;">No Pengeluaran</td>
            <td style="border:1px solid black; padding:5px;">Tgl</td>
            <td style="border:1px solid black; padding:5px;">Jumlah</td>
            <td style="border:1px solid black; padding:5px;">Catatan</td>
            <td style="border:1px solid black; padding:5px;">t_pi_bahan_id</td>
            <td style="border:1px solid black; padding:5px;">t_po_bahan_id</td>
            <td style="border:1px solid black; padding:5px;">t_po_bahan_h_id</td>
            <td style="border:1px solid black; padding:5px;">resolved_from_pi_supplier_id</td>
            <td style="border:1px solid black; padding:5px;">resolved_from_po_supplier_id</td>
            <td style="border:1px solid black; padding:5px;">resolved_from_poh_supplier_id</td>
          </tr>
        </thead>
        <tbody>
          @foreach($rows as $r)
            <tr>
              <td style="border:1px solid black; padding:5px; text-align:center;">{{ $r->id }}</td>
              <td style="border:1px solid black; padding:5px;">{{ $r->no_pengeluaran ?? '' }}</td>
              <td style="border:1px solid black; padding:5px;">{{ $r->tanggal ?? '' }}</td>
              <td style="border:1px solid black; padding:5px; text-align:right;">{{ $fmtRupiah($r->jumlah ?? 0) }}</td>
              <td style="border:1px solid black; padding:5px;">{{ $r->keterangan ?? '' }}</td>
              <td style="border:1px solid black; padding:5px; text-align:center;">{{ $r->t_pi_bahan_id ?? '-' }}</td>
              <td style="border:1px solid black; padding:5px; text-align:center;">{{ $r->t_po_bahan_id ?? '-' }}</td>
              <td style="border:1px solid black; padding:5px; text-align:center;">{{ $r->t_po_bahan_h_id ?? '-' }}</td>
              <td style="border:1px solid black; padding:5px; text-align:center;">{{ $r->resolved_from_pi_supplier_id ?? '-' }}</td>
              <td style="border:1px solid black; padding:5px; text-align:center;">{{ $r->resolved_from_po_supplier_id ?? '-' }}</td>
              <td style="border:1px solid black; padding:5px; text-align:center;">{{ $r->resolved_from_poh_supplier_id ?? '-' }}</td>
            </tr>
          @endforeach
        </tbody>
      </table>
    @endforeach
  @endif -->
            <!-- 
<br>
<div><h3 style="text-align:center; font-weight:bold;">Daftar Pengeluaran Kas (Recent, status=POST)</h3></div>
<table style="border:1px solid black; border-collapse:collapse; width:100%; font-size:11px;">
  <thead>
    <tr style="background:#f2f2f2; font-weight:bold; text-align:center;">
      <td style="border:1px solid black; padding:5px;">No.</td>
      <td style="border:1px solid black; padding:5px;">No Pengeluaran</td>
      <td style="border:1px solid black; padding:5px;">Tgl</td>
      <td style="border:1px solid black; padding:5px;">Jenis</td>
      <td style="border:1px solid black; padding:5px;">No PO</td>
      <td style="border:1px solid black; padding:5px;">No PI</td>
      <td style="border:1px solid black; padding:5px;">Jumlah</td>
      <td style="border:1px solid black; padding:5px;">Nama Supplier</td>
      <td style="border:1px solid black; padding:5px;">Catatan</td>
      <td style="border:1px solid black; padding:5px;">Status</td>
    </tr>
  </thead>
  <tbody>
    @php $nrow = 1; @endphp
    @foreach($pengeluaranRows as $p)
      <tr>
        <td style="border:1px solid black; padding:5px; text-align:center;">{{ $nrow++ }}</td>
        <td style="border:1px solid black; padding:5px;">{{ $p->no_pengeluaran }}</td>
        <td style="border:1px solid black; padding:5px;">{{ $p->tgl }}</td>
  <td style="border:1px solid black; padding:5px;">{{ $p->tipe }}</td>
        <td style="border:1px solid black; padding:5px;">{{ $p->no_po ?? '-' }}</td>
        <td style="border:1px solid black; padding:5px;">{{ $p->no_pi ?? '-' }}</td>
        @php $pengeluaranDisplay = $isExcelExport ? $fmtExportPlain($p->jumlah ?? 0) : $fmtBrowser($p->jumlah ?? 0); @endphp
        <td style="border:1px solid black; padding:5px; text-align:right; {{ $cellNumberStyle }}">{{ $pengeluaranDisplay }}</td>
  <td style="border:1px solid black; padding:5px;">{{ $p->resolved_supplier_name ?? '-' }}</td>
        <td style="border:1px solid black; padding:5px;">{{ $p->keterangan ?? '' }}</td>
        <td style="border:1px solid black; padding:5px; text-align:center;">{{ $p->status }}</td>
      </tr>
    @endforeach
  </tbody>
</table> -->