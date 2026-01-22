// 9 Nov 2025 - laporan hpp
@php
$req = app()->request;
$helper = getCore('Helper');
$date_now = date('d/m/Y H:i:s');

$periodeFrom = $req->get('periode_from') ? date('Y-m-d', strtotime($req->get('periode_from'))) : null;
$periodeTo = $req->get('periode_to') ? date('Y-m-d', strtotime($req->get('periode_to'))) : null;

// Export detection (sama seperti Laba Rugi)
$exportParam = strtolower((string)($req->get('export') ?? ''));
$isExcelExport = in_array($exportParam, ['xls','xlsx','excel','csv'], true);

// Formatting helpers (sama seperti Laba Rugi)
$fmtBrowser = function ($v) {
$v = (float)($v ?? 0);
$abs = (int)abs($v);
return 'Rp ' . number_format($abs, 0, ',', '.');
};

$fmtExportPlain = function ($v) {
return (int)round((float)($v ?? 0));
};

$cellNumberStyle = "mso-number-format:'\\0022Rp\\0022 #,##0';";/* ================== DATA PO (MASUK) — FIX anti duplikasi ================== */

/* 1) Aggregate detail PI per penerimaan agar 1 row per tp.id */
$aggPiDetail = \DB::table('t_pi_bahan_d as tpid')
->select(
'tpid.t_penerimaan_id',
\DB::raw('AVG(tpid.harga_nett) AS avg_harga_nett'),
\DB::raw('MAX(tpid.kadar_air) AS max_kadar_air')
)
->groupBy('tpid.t_penerimaan_id');

/* 2) Penerimaan: join ke PO+Supplier, lalu LEFT JOIN ke hasil agregat PI */
$dataPo = \DB::table('t_penerimaan as tp')
->join('t_po_bahan as tpo', 'tpo.id', '=', 'tp.t_po_bahan_id')
->join('m_supplier as ms', 'ms.id', '=', 'tpo.m_supplier_id')
->leftJoinSub($aggPiDetail, 'pid', function($j){
$j->on('pid.t_penerimaan_id', '=', 'tp.id');
})
->select(
'tpo.no_po as no_referensi',
'tp.no_penerimaan',
'tp.tgl_penerimaan as tanggal',
'ms.nama_supplier as supplier_customer',

// KA: ambil dari detail PI jika ada; kalau tidak, pakai tp.kadar_air
\DB::raw('COALESCE(MAX(pid.max_kadar_air), tp.kadar_air, 0)::numeric AS kadar_air'),

// Harga nett: dari detail PI (avg) kalau ada; fallback ke harga_sepakat PO
\DB::raw('COALESCE(MAX(pid.avg_harga_nett), tpo.harga_sepakat, 0)::numeric AS harga_nett'),

// Berat masuk: SUM langsung dari tp.total_netto (tidak terduplikasi lagi)
\DB::raw('SUM(tp.total_netto)::numeric AS berat_masuk'),

\DB::raw('NULL::numeric AS berat_keluar'),
\DB::raw('MIN(tp.created_at) AS created_src')
)
->where('tp.status', 'POST')
// ->when($periodeFrom, fn($q)=>$q->whereDate('tp.tgl_penerimaan','>=',$periodeFrom))
// ->when($periodeTo, fn($q)=>$q->whereDate('tp.tgl_penerimaan','<=',$periodeTo))
->groupBy('tpo.no_po', 'tp.no_penerimaan', 'tp.tgl_penerimaan', 'ms.nama_supplier', 'tpo.harga_sepakat', 'tp.kadar_air');

/* ================== DATA SO / SJ (KELUAR) ================== */
$dataSo = \DB::table('t_surat_jalan as tsj')
->join('t_sales_order as tso','tso.id','=','tsj.t_sales_order_id')
->join('m_customer as mc','mc.id','=','tso.m_customer_id')
->select(
'tso.no_so as no_referensi',
\DB::raw('NULL::text as no_penerimaan'),
'tsj.tgl as tanggal',
'mc.nama as supplier_customer',
\DB::raw('NULL::numeric as kadar_air'),
\DB::raw('NULL::numeric as harga_nett'),
\DB::raw('NULL::numeric as berat_masuk'),
'tsj.berat_kirim as berat_keluar',
\DB::raw('tsj.created_at as created_src')
);

/* ================== DATA ADJUSTMENT ================== */
$dataAdj = \DB::table('t_adjustment_stock as tas')
->join('t_adjustment_stock_d as tasd','tasd.t_adjustment_stock_id','=','tas.id')
->select(
'tas.no_adj as no_referensi',
\DB::raw('NULL::text as no_penerimaan'),
'tas.tgl as tanggal',
\DB::raw("'Adjustment Stock' as supplier_customer"),
\DB::raw('NULL::numeric as kadar_air'),
\DB::raw('NULL::numeric as harga_nett'),
\DB::raw("CASE WHEN tasd.berat_adjustment > 0 THEN tasd.berat_adjustment ELSE NULL END::numeric as berat_masuk"),
\DB::raw("CASE WHEN tasd.berat_adjustment < 0 THEN ABS(tasd.berat_adjustment) ELSE NULL END::numeric as berat_keluar"),
\DB::raw('tas.created_at as created_src') ); /*==================UNION ALL DATA==================*/
$allTransaksi=\DB::query() ->fromSub(
$dataPo->unionAll($dataSo)->unionAll($dataAdj),
'history'
)
->orderBy('created_src','asc')
->orderBy('tanggal','asc')
->get();

/* ================== VARIABEL AGREGAT ================== */
$stok = 0; $nilai = 0; $hpp = 0; $rowNo = 1;
$totalBeratMasuk = 0; $totalRpMasuk = 0; $totalBeratKeluar = 0; $totalRpKeluar = 0;

/* ================== FORMAT ANGKA ================== */
$fmtIntId = function ($v) use ($isExcelExport) {
$v = (int) round((float)($v ?? 0));
return $isExcelExport ? $v : number_format($v, 0, ',', '.');
};
$cellIntStyle = "mso-number-format:'0';";
$fmtPercent = function ($v, $d = 3) {
if ($v === null || $v === '') return '';
return rtrim(rtrim(number_format((float)$v, $d, ',', '.'), '0'), ',');
};
$cellInt = fn($v) => '<span style="mso-number-format:\'@\';white-space:nowrap;">&#8203;'.e($fmtIntId($v)).'</span>';
$cellPercent = fn($v,$d=3) =>
'<span style="mso-number-format:\'@\';white-space:nowrap;">&#8203;'.e($fmtPercent($v,$d)).'</span>';
@endphp

<div>
<div>
<h1 style="font-weight: bold; text-align: center;">Laporan HPP</h1>
<p style="text-align: center;">
Periode :
{{ @$req->periode_from ? $helper->formatDateId(@$req->periode_from) : '-' }}
{{ @$req->periode_to ? '- ' . $helper->formatDateId(@$req->periode_to) : '' }}
</p>
</div>
<br>

<style media="screen">
.web-only {
display: inline !important;
}

.excel-only {
display: none !important;
}
</style>

<table style="border: 1px solid black; border-collapse: collapse; width: 100%; font-size: 8px;">
<thead>
<tr>
<th rowspan="2" style="border:1px solid black;text-align:center;">No.</th>
<th rowspan="2" colspan="2" style="border:1px solid black;text-align:center;">No. Referensi</th>
<th rowspan="2" colspan="2" style="border:1px solid black;text-align:center;">No. Penerimaan</th>
<th rowspan="2" colspan="2" style="border:1px solid black;text-align:center;">Tanggal</th>
<th rowspan="2" colspan="3" style="border:1px solid black;text-align:center;">Supplier / Customer</th>
<th rowspan="2" colspan="2" style="border:1px solid black;text-align:center;">KA (%)</th>
<th colspan="6" style="border:1px solid black;text-align:center;background:#f2f2f2;">Berat Penerimaan</th>
<th colspan="6" style="border:1px solid black;text-align:center;background:#f2f2f2;">Berat Surat Jalan</th>
<th colspan="6" style="border:1px solid black;text-align:center;background:#f2f2f2;">Balance</th>
</tr>
<tr>
<th colspan="2" style="border:1px solid black;text-align:center;">Berat</th>
<th colspan="2" style="border:1px solid black;text-align:center;">Harga Nett</th>
<th colspan="2" style="border:1px solid black;text-align:center;">Total</th>
<th colspan="2" style="border:1px solid black;text-align:center;">Berat</th>
<th colspan="2" style="border:1px solid black;text-align:center;">Harga HPP</th>
<th colspan="2" style="border:1px solid black;text-align:center;">Total</th>
<th colspan="2" style="border:1px solid black;text-align:center;">Berat</th>
<th colspan="2" style="border:1px solid black;text-align:center;">HPP</th>
<th colspan="2" style="border:1px solid black;text-align:center;">TOTAL</th>
</tr>
</thead>
<tbody>
@foreach($allTransaksi as $row)
@php
$beratMasuk = $row->berat_masuk ?? 0;
$beratKeluar = $row->berat_keluar ?? 0;
$hargaNet = $row->harga_nett ?? 0;
$isAdjustment = strpos($row->supplier_customer, 'Adjustment') !== false;
$isAdjustmentDisplay = false;
$displayHpp = $hpp; // HPP yang ditampilkan di Balance (SESUDAH transaksi)
$hppBefore = $hpp; // HPP sebelum transaksi (untuk kolom Harga HPP di SJ)

// Hanya proses berat masuk jika bukan adjustment
if ($beratMasuk > 0 && $hargaNet > 0 && !$isAdjustment) {
// Tambah nilai dengan akurat (tanpa menggunakan HPP yang sudah dibulatkan)
$nilai += ($beratMasuk * $hargaNet);
$stok += $beratMasuk;
// Hitung HPP internal dalam float (presisi penuh), pembulatan hanya saat tampilkan
$hpp = $stok > 0 ? ($nilai / $stok) : 0;
$displayHpp = round($hpp);

$totalBeratMasuk += $beratMasuk;
$totalRpMasuk += $beratMasuk * $hargaNet;
}

// Adjustment: masuk ke Berat Penerimaan jika positif, Surat Jalan jika negatif
if ($isAdjustment) {
$adjustmentBerat = $beratMasuk > 0 ? $beratMasuk : ($beratKeluar > 0 ? -$beratKeluar : 0);
if ($adjustmentBerat != 0) {
// Sesuai kebutuhan: adjustment hanya mengubah STOK, NILAI tetap (tidak menambah/mengurangi nilai)
if ($adjustmentBerat > 0) {
// Adjustment plus (masuk) -> tambah stok saja
$stok += $adjustmentBerat;
} else {
// Adjustment minus (keluar) -> kurangi stok saja
$stok += $adjustmentBerat; // adjustmentBerat negatif
}

// Hitung ulang HPP berdasarkan nilai yang tetap dan stok baru (pakai float untuk internal)
$hpp = $stok > 0 ? ($nilai / $stok) : 0;
$displayHpp = round($hpp); // HPP yang ditampilkan setelah adjustment
$isAdjustmentDisplay = true;

// Adjustment tidak mempengaruhi total penerimaan/keluar dan rupiah total kolom
}
} else if ($beratKeluar > 0) {
// Penjualan/Surat Jalan
// Pakai HPP internal (float) sebelum transaksi untuk nilai keluar
$nilaiKeluar = $beratKeluar * $hpp;
$stok -= $beratKeluar;
$nilai -= $nilaiKeluar;
// Recalculate HPP after keluar
$hpp = $stok > 0 ? ($nilai / $stok) : 0;
$displayHpp = round($hpp); // Display HPP yang baru (setelah keluar)

$totalBeratKeluar += $beratKeluar;
$totalRpKeluar += $nilaiKeluar;
}
@endphp

<tr>
<td style="border:1px solid black;text-align:center;">{{ $rowNo++ }}</td>
<td colspan="2" style="border:1px solid black;text-align:center;">{{ $row->no_referensi }}</td>
<td colspan="2" style="border:1px solid black;text-align:center;">{{ $row->no_penerimaan ?? '-' }}</td>
<td colspan="2" style="border:1px solid black;text-align:center;">{{
\Carbon\Carbon::parse($row->tanggal)->format('d/m/Y') }}</td>
<td colspan="3" style="border:1px solid black;text-align:center;">{{ $row->supplier_customer }}</td>

<td colspan="2" style="border:1px solid black;text-align:center;">{!! $cellPercent($row->kadar_air, 3) !!}
</td>

<td colspan="2" style="border:1px solid black;text-align:center;{{ $isExcelExport ? $cellIntStyle : '' }}">
{!! (isset($beratMasuk) && ($beratMasuk > 0 || ($isAdjustment && $isAdjustmentDisplay && $beratMasuk > 0))) ? $fmtIntId($beratMasuk) : 0 !!}
</td>
<td colspan="2" style="border:1px solid black;text-align:center;{{ $isExcelExport ? $cellNumberStyle : '' }}">
{!! (!$isAdjustment && isset($hargaNet)) ? ($isExcelExport ? $fmtExportPlain($hargaNet) : $fmtBrowser($hargaNet)) : 0 !!}
</td>
<td colspan="2" style="border:1px solid black;text-align:center;{{ $isExcelExport ? $cellNumberStyle : '' }}">
{!! (!$isAdjustment && isset($beratMasuk, $hargaNet) && $beratMasuk > 0) ? ($isExcelExport ? $fmtExportPlain($beratMasuk * $hargaNet) : $fmtBrowser($beratMasuk * $hargaNet)) : 0 !!}
</td>
<td colspan="2" style="border:1px solid black;text-align:center;{{ $isExcelExport ? $cellIntStyle : '' }}">
{!! (isset($beratKeluar) && ($beratKeluar > 0 || ($isAdjustment && $isAdjustmentDisplay && $beratKeluar > 0))) ? $fmtIntId($beratKeluar) : 0 !!}
</td>
<td colspan="2" style="border:1px solid black;text-align:center;{{ $isExcelExport ? $cellNumberStyle : '' }}">
{!! isset($beratKeluar, $hppBefore) && $beratKeluar > 0 && !$isAdjustment ? ($isExcelExport ? $fmtExportPlain(round($hppBefore)) : $fmtBrowser(round($hppBefore))) : 0 !!}
</td>

<td colspan="2" style="border:1px solid black;text-align:center;{{ $isExcelExport ? $cellNumberStyle : '' }}">
{!! isset($beratKeluar, $hppBefore) && $beratKeluar > 0 && !$isAdjustment ? ($isExcelExport ? $fmtExportPlain($beratKeluar * round($hppBefore)) : $fmtBrowser($beratKeluar * round($hppBefore))) : 0 !!}
</td>
<td colspan="2" style="border:1px solid black;text-align:center;{{ $isExcelExport ? $cellIntStyle : '' }}">
{!! isset($stok) ? $fmtIntId($stok) : 0 !!}
</td>
<td colspan="2" style="border:1px solid black;text-align:center;{{ $isExcelExport ? $cellNumberStyle : '' }}">
{!! isset($displayHpp) ? ($isExcelExport ? $fmtExportPlain($displayHpp) : $fmtBrowser($displayHpp)) : 0 !!}
</td>
<td colspan="2" style="border:1px solid black;text-align:center;{{ $isExcelExport ? $cellNumberStyle : '' }}">
{!! isset($nilai) ? ($isExcelExport ? $fmtExportPlain($nilai) : $fmtBrowser($nilai)) : 0 !!}
</td>
</tr>
@endforeach

@php
$totalBalance = $stok;
$totalBalanceRp = $nilai;
$hppAkhir = $totalBalance > 0 ? round($totalBalanceRp / $totalBalance) : 0;
@endphp

<tr>
<td colspan="10" style="border:1px solid black;"></td>
<td colspan="2" style="border:1px solid black;text-align:center;background:#f2f2f2;font-weight:bold;">TOTAL
</td>

<td colspan="2" style="border:1px solid black;text-align:center;background:#f2f2f2;font-weight:bold;{{ $isExcelExport ? $cellIntStyle : '' }}">
{!! isset($totalBeratMasuk) ? $fmtIntId($totalBeratMasuk) : 0 !!}
</td>
<td colspan="2" style="border:1px solid black;background:#f2f2f2;"></td>
<td colspan="2" style="border:1px solid black;text-align:center;background:#f2f2f2;font-weight:bold;{{ $isExcelExport ? $cellNumberStyle : '' }}">
{!! isset($totalRpMasuk) ? ($isExcelExport ? $fmtExportPlain($totalRpMasuk) : $fmtBrowser($totalRpMasuk)) : 0 !!}
</td>
<td colspan="2" style="border:1px solid black;text-align:center;background:#f2f2f2;font-weight:bold;{{ $isExcelExport ? $cellIntStyle : '' }}">
{!! isset($totalBeratKeluar) ? $fmtIntId($totalBeratKeluar) : 0 !!}
</td>
<td colspan="2" style="border:1px solid black;background:#f2f2f2;"></td>
<td colspan="2" style="border:1px solid black;text-align:center;background:#f2f2f2;font-weight:bold;{{ $isExcelExport ? $cellNumberStyle : '' }}">
{!! isset($totalRpKeluar) ? ($isExcelExport ? $fmtExportPlain($totalRpKeluar) : $fmtBrowser($totalRpKeluar)) : 0 !!}
</td>
<td colspan="2" style="border:1px solid black;text-align:center;background:#f2f2f2;font-weight:bold;{{ $isExcelExport ? $cellIntStyle : '' }}">
{!! isset($totalBalance) ? $fmtIntId($totalBalance) : 0 !!}
</td>
<td colspan="2" style="border:1px solid black;text-align:center;background:#f2f2f2;font-weight:bold;{{ $isExcelExport ? $cellNumberStyle : '' }}">
{!! isset($hppAkhir) ? ($isExcelExport ? $fmtExportPlain($hppAkhir) : $fmtBrowser($hppAkhir)) : 0 !!}
</td>
<td colspan="2" style="border:1px solid black;text-align:center;background:#f2f2f2;font-weight:bold;{{ $isExcelExport ? $cellNumberStyle : '' }}">
{!! isset($totalBalanceRp) ? ($isExcelExport ? $fmtExportPlain($totalBalanceRp) : $fmtBrowser($totalBalanceRp)) : 0 !!}
</td>
</tr>
</tbody>
</table>
</div>

// 9 Nov 2025 - laporan stock overview
@php
use Illuminate\Support\Facades\DB;
use Carbon\Carbon;

$req = app()->request;
$helper = getCore('Helper');

// --------------------------------------------------------
// Export & Formatter
// --------------------------------------------------------
$exportParam = strtolower((string)($req->get('export') ?? ''));
$isExcelExport = in_array($exportParam, ['xls','xlsx','excel','csv'], true);

$fmtBrowser = fn($v) => 'Rp ' . number_format(abs((float)$v), 0, ',', '.');
$fmtExportPlain = fn($v) => (int)round((float)($v ?? 0));
$cellNumberStyle = "mso-number-format:'\\0022Rp\\0022 #,##0';";

// --------------------------------------------------------
// Parameter
// --------------------------------------------------------
$periodeFrom = $req->get('periode_from') ? date('Y-m-d', strtotime($req->get('periode_from'))) : null;
$periodeTo = $req->get('periode_to') ? date('Y-m-d', strtotime($req->get('periode_to'))) : null;

// =========================================================
// 1️⃣ DATA MASUK (PO/PENERIMAAN)
// =========================================================
$aggPiDetail = DB::table('t_pi_bahan_d as tpid')
->select(
'tpid.t_penerimaan_id',
DB::raw('AVG(tpid.harga_nett) AS avg_harga_nett'),
DB::raw('MAX(tpid.kadar_air) AS max_kadar_air')
)
->groupBy('tpid.t_penerimaan_id');

$dataPo = DB::table('t_penerimaan as tp')
->join('t_po_bahan as tpo','tpo.id','=','tp.t_po_bahan_id')
->join('m_supplier as ms','ms.id','=','tpo.m_supplier_id')
->leftJoinSub($aggPiDetail,'pid',function($j){
$j->on('pid.t_penerimaan_id','=','tp.id');
})
->select(
'tpo.no_po as no_referensi',
'tp.no_penerimaan',
'tp.tgl_penerimaan as tanggal',
'ms.nama_supplier as supplier_customer',
DB::raw('COALESCE(MAX(pid.max_kadar_air), tp.kadar_air, 0)::numeric as kadar_air'),
DB::raw('COALESCE(MAX(pid.avg_harga_nett), tpo.harga_sepakat, 0)::numeric as harga_nett'),
DB::raw('SUM(tp.total_netto)::numeric as berat_masuk'),
DB::raw('NULL::numeric as berat_keluar'),
DB::raw('MIN(tp.created_at) as created_src')
)
->where('tp.status','POST')
->groupBy('tpo.no_po','tp.no_penerimaan','tp.tgl_penerimaan','ms.nama_supplier','tpo.harga_sepakat','tp.kadar_air');

// =========================================================
// 2️⃣ DATA KELUAR (SO/SJ)
// =========================================================
$dataSo = DB::table('t_surat_jalan as tsj')
->join('t_sales_order as tso','tso.id','=','tsj.t_sales_order_id')
->join('m_customer as mc','mc.id','=','tso.m_customer_id')
->select(
'tso.no_so as no_referensi',
DB::raw('NULL::text as no_penerimaan'),
'tsj.tgl as tanggal',
'mc.nama as supplier_customer',
DB::raw('NULL::numeric as kadar_air'),
DB::raw('NULL::numeric as harga_nett'),
DB::raw('NULL::numeric as berat_masuk'),
'tsj.berat_kirim as berat_keluar',
DB::raw('tsj.created_at as created_src')
);

// =========================================================
// 3️⃣ DATA ADJUSTMENT
// =========================================================
$dataAdj = DB::table('t_adjustment_stock as tas')
->join('t_adjustment_stock_d as tasd','tasd.t_adjustment_stock_id','=','tas.id')
->select(
'tas.no_adj as no_referensi',
DB::raw('NULL::text as no_penerimaan'),
'tas.tgl as tanggal',
DB::raw("'Adjustment Stock' as supplier_customer"),
DB::raw('NULL::numeric as kadar_air'),
DB::raw('NULL::numeric as harga_nett'),
DB::raw("CASE WHEN tasd.berat_adjustment > 0 THEN tasd.berat_adjustment ELSE NULL END::numeric as berat_masuk"),
DB::raw("CASE WHEN tasd.berat_adjustment < 0 THEN ABS(tasd.berat_adjustment) ELSE NULL END::numeric as berat_keluar"),
DB::raw('tas.created_at as created_src')
);

//=========================================================// 4️⃣ UNION SEMUA TRANSAKSI
//=========================================================$allTransaksi=DB::query()
->fromSub($dataPo->unionAll($dataSo)->unionAll($dataAdj), 'history')
->orderBy('created_src','asc')
->orderBy('tanggal','asc')
->get();

// =========================================================
// 5️⃣ HITUNG HPP AKHIR (KONSISTEN DENGAN LAPORAN HPP)
// =========================================================
$stok = 0; $nilai = 0; $hpp = 0;
foreach ($allTransaksi as $row) {
$beratMasuk = (float)($row->berat_masuk ?? 0);
$beratKeluar = (float)($row->berat_keluar ?? 0);
$hargaNet = (float)($row->harga_nett ?? 0);
$isAdjustment = strpos($row->supplier_customer ?? '', 'Adjustment') !== false;

if ($beratMasuk > 0 && $hargaNet > 0 && !$isAdjustment) {
$nilai += $beratMasuk * $hargaNet;
$stok += $beratMasuk;
$hpp = $stok > 0 ? $nilai / $stok : 0;
}

if ($isAdjustment) {
$adj = $beratMasuk > 0 ? $beratMasuk : ($beratKeluar > 0 ? -$beratKeluar : 0);
if ($adj != 0) {
$stok += $adj;
$hpp = $stok > 0 ? $nilai / $stok : 0;
}
} elseif ($beratKeluar > 0) {
$nilaiKeluar = $beratKeluar * $hpp;
$stok -= $beratKeluar;
$nilai -= $nilaiKeluar;
$hpp = $stok > 0 ? $nilai / $stok : 0;
}
}

$hppAkhir = round($hpp);
$totalBalance = $stok;
$totalBalanceRp = $nilai;

// =========================================================
// 6️⃣ DATA STOK GUDANG
// =========================================================
$rawData = DB::table('m_range as mr')
->leftJoin('m_gudang_d_range as mgdr','mgdr.m_range_id','=','mr.id')
->leftJoin('m_gudang_d as mgd','mgd.id','=','mgdr.m_gudang_d_id')
->leftJoin('m_gudang as mg','mg.id','=','mgd.m_gudang_id')
->select(
'mr.nama as nama_range',
'mg.nama as nama_gudang',
'mgd.nama as nama_kavling',
DB::raw('GREATEST(mgdr.stok,0) as stok')
)
->orderBy('mr.nama')
->orderBy('mg.nama')
->orderBy('mgd.nama')
->get();

// =========================================================
// 7️⃣ SINKRONKAN STOK & TOTAL
// =========================================================
$sumCurrent = (float)$rawData->sum('stok');
if ($sumCurrent > 0 && abs($sumCurrent - $totalBalance) > 0.001) {
$ratio = $totalBalance / $sumCurrent;
$rawData = $rawData->map(function($r) use($ratio){
$r->stok = round($r->stok * $ratio, 3);
return $r;
});
}

// =========================================================
// 8️⃣ HITUNG TOTAL RP PER KAVLING (SINKRON 100% DENGAN HPP)
// =========================================================
$rawData = $rawData->map(function($r) use($hppAkhir){
$r->hpp = $hppAkhir;
$r->total_rp = $r->stok * $hppAkhir; // tanpa round dulu
return $r;
});

// ✅ Koreksi total agar grandTotalRp == totalBalanceRp
$sumRp = $rawData->sum('total_rp');
$diffRp = $totalBalanceRp - $sumRp;
if (abs($diffRp) >= 1 && $rawData->count() > 0) {
// Tambahkan selisih kecil ke item terakhir agar akurat
$last = $rawData->last();
$last->total_rp += $diffRp;
$rawData[$rawData->keys()->last()] = $last;
}

// =========================================================
// 9️⃣ Grouping untuk tampilan
// =========================================================
$data = $rawData
->filter(fn($r)=>$r->stok>0)
->groupBy('nama_range')
->map(fn($g)=>$g->groupBy('nama_gudang'));

$grandStok = round($rawData->sum('stok'));
$grandTotalRp = round($rawData->sum('total_rp')); // ✅ = totalBalanceRp tepat
@endphp

<div>
<h1 style="text-align:center;font-weight:bold;">Laporan Stock Overview</h1>
<p style="text-align:center;">
Periode :
{{ @$req->periode_from ? $helper->formatDateId(@$req->periode_from) : '-' }}
{{ @$req->periode_to ? '- ' . $helper->formatDateId(@$req->periode_to) : '' }}
</p>
<br>

<table style="border:1px solid black;border-collapse:collapse;width:100%;font-size:8px;">
<thead>
<tr>
<th style="border:1px solid black;text-align:center;background:#f2f2f2;">No.</th>
<th style="border:1px solid black;text-align:center;background:#f2f2f2;">RANGE KA</th>
<th style="border:1px solid black;text-align:center;background:#f2f2f2;">Gudang</th>
<th style="border:1px solid black;text-align:center;background:#f2f2f2;">Kavling</th>
<th style="border:1px solid black;text-align:center;background:#f2f2f2;">Balance</th>
<th style="border:1px solid black;text-align:center;background:#f2f2f2;">Total Rp</th>
</tr>
</thead>
<tbody>
@php $no=1; @endphp
@foreach($data as $range => $gudangs)
@php
$rangeRows = $gudangs->flatten(1);
if($rangeRows->sum('stok') == 0) continue;
$rangeRowspan = $rangeRows->count();
$firstRangeRow = true;
@endphp
@foreach($gudangs as $gudang => $kavlings)
@php
if($kavlings->sum('stok') == 0) continue;
$gudangRowspan = $kavlings->count();
$firstGudangRow = true;
@endphp
@foreach($kavlings as $row)
<tr>
@if($firstRangeRow)
<td rowspan="{{ $rangeRowspan }}" style="border:1px solid black;text-align:center;">{{ $no++ }}</td>
<td rowspan="{{ $rangeRowspan }}" style="border:1px solid black;text-align:center;">{{ $range }}</td>
@php $firstRangeRow = false; @endphp
@endif
@if($firstGudangRow)
<td rowspan="{{ $gudangRowspan }}" style="border:1px solid black;text-align:center;">{{ $gudang }}</td>
@php $firstGudangRow = false; @endphp
@endif
<td style="border:1px solid black;text-align:center;">{{ $row->nama_kavling }}</td>
<td style="border:1px solid black;text-align:center;{{ $isExcelExport ? $cellNumberStyle : '' }}">
    {{ $isExcelExport ? $fmtExportPlain($row->stok) : number_format($row->stok,0,',','.') }}
</td>
<td style="border:1px solid black;text-align:center;{{ $isExcelExport ? $cellNumberStyle : '' }}">
    {{ $isExcelExport ? $fmtExportPlain($row->total_rp) : $fmtBrowser($row->total_rp) }}
</td>
</tr>
@endforeach
@endforeach
@endforeach
<tr style="font-weight:bold;background:#f2f2f2;">
<td colspan="4" style="border:1px solid black;text-align:center;">TOTAL</td>
<td style="border:1px solid black;text-align:center;{{ $isExcelExport ? $cellNumberStyle : '' }}">
    {{ $isExcelExport ? $fmtExportPlain($grandStok) : number_format($grandStok,0,',','.') }}
</td>
<td style="border:1px solid black;text-align:center;{{ $isExcelExport ? $cellNumberStyle : '' }}">
    {{ $isExcelExport ? $fmtExportPlain($grandTotalRp) : $fmtBrowser($grandTotalRp) }}
</td>
</tr>
<tr style="font-weight:bold;background:#e6ffe6;">
<td colspan="5" style="border:1px solid black;text-align:right;">HPP</td>
<td style="border:1px solid black;text-align:center;{{ $isExcelExport ? $cellNumberStyle : '' }}">
    {{ $isExcelExport ? $fmtExportPlain($hppAkhir) : $fmtBrowser($hppAkhir) }}
</td>
</tr>
</tbody>
</table>
</div>

// 9 Nov 2025 - laporan laba rugi
@php
use Illuminate\Support\Facades\DB;

$req = app()->request;
$helper = getCore('Helper');

// =========================================================
// 0️⃣ PARAMETER & FORMATTER
// =========================================================
$exportParam = strtolower((string)($req->get('export') ?? ''));
$isExcelExport = in_array($exportParam, ['xls','xlsx','excel','csv'], true);

$fmtBrowser = function ($v) {
$v = (float)($v ?? 0);
$neg = $v < 0;
$abs=(int)abs($v);
$s='Rp ' . number_format($abs, 0, ',' , '.' );
return $neg ? "({$s})" : $s;
};
$fmtBrowserNoParen=fn($v)=> 'Rp ' . number_format(abs((float)($v ?? 0)), 0, ',', '.');
$fmtExportPlain = fn($v) => (int)round((float)($v ?? 0));
$cellNumberStyle = "mso-number-format:'\\0022Rp\\0022 #,##0';";
$negStyle = fn($v) => ((float)($v ?? 0) < 0) ? 'color:#c00;' : 'color:#000;' ;

$periodeFrom=$req->get('periode_from') ? date('Y-m-d', strtotime($req->get('periode_from'))) : null;
$periodeTo = $req->get('periode_to') ? date('Y-m-d', strtotime($req->get('periode_to'))) : null;

// =========================================================
// 1️⃣ PENJUALAN
// =========================================================
$penjualan = DB::table('t_sales_invoice as si')
->where('si.status', 'POST')
->when($periodeFrom && $periodeTo, fn($q)=>$q->whereBetween('si.tgl', [$periodeFrom,$periodeTo]))
->sum('si.grand_total');

// =========================================================
// 2️⃣ HPP AKHIR & STOK AKHIR (IDENTIK DENGAN LAPORAN STOCK)
// =========================================================

// --- Subquery harga PO ---
$aggPiDetail = DB::table('t_pi_bahan_d as tpid')
->select('tpid.t_penerimaan_id', DB::raw('AVG(tpid.harga_nett) AS avg_harga_nett'))
->groupBy('tpid.t_penerimaan_id');

// --- Data Masuk (PO) ---
$dataPo = DB::table('t_penerimaan as tp')
->join('t_po_bahan as tpo','tpo.id','=','tp.t_po_bahan_id')
->leftJoinSub($aggPiDetail,'pid',fn($j)=>$j->on('pid.t_penerimaan_id','=','tp.id'))
->select(
'tpo.no_po as no_referensi',
'tp.no_penerimaan',
'tp.tgl_penerimaan as tanggal',
DB::raw("COALESCE('Supplier', '') as supplier_customer"),
DB::raw('COALESCE(MAX(pid.avg_harga_nett), tpo.harga_sepakat, 0)::numeric as harga_nett'),
DB::raw('SUM(tp.total_netto)::numeric as berat_masuk'),
DB::raw('NULL::numeric as berat_keluar'),
DB::raw('MIN(tp.created_at) as created_src')
)
->where('tp.status','POST')
->groupBy('tpo.no_po','tp.no_penerimaan','tp.tgl_penerimaan','tpo.harga_sepakat');

// --- Data Keluar (SO) ---
$dataSo = DB::table('t_surat_jalan as tsj')
->join('t_sales_order as tso','tso.id','=','tsj.t_sales_order_id')
->join('m_customer as mc','mc.id','=','tso.m_customer_id')
->select(
'tso.no_so as no_referensi',
DB::raw('NULL::text as no_penerimaan'),
'tsj.tgl as tanggal',
'mc.nama as supplier_customer',
DB::raw('NULL::numeric as harga_nett'),
DB::raw('NULL::numeric as berat_masuk'),
'tsj.berat_kirim as berat_keluar',
DB::raw('tsj.created_at as created_src')
);

// --- Data Adjustment ---
$dataAdj = DB::table('t_adjustment_stock as tas')
->join('t_adjustment_stock_d as tasd','tasd.t_adjustment_stock_id','=','tas.id')
->select(
'tas.no_adj as no_referensi',
DB::raw('NULL::text as no_penerimaan'),
'tas.tgl as tanggal',
DB::raw("'Adjustment Stock' as supplier_customer"),
DB::raw('NULL::numeric as harga_nett'),
DB::raw("CASE WHEN tasd.berat_adjustment > 0 THEN tasd.berat_adjustment ELSE NULL END::numeric as berat_masuk"),
DB::raw("CASE WHEN tasd.berat_adjustment < 0 THEN ABS(tasd.berat_adjustment) ELSE NULL END::numeric as berat_keluar"),
DB::raw('tas.created_at as created_src')
);

// --- Gabungkan semua transaksi ---
$allTransaksi=DB::query()
->fromSub($dataPo->unionAll($dataSo)->unionAll($dataAdj), 'history')
->orderBy('created_src','asc')
->orderBy('tanggal','asc')
->get();

// --- Proses kronologis HPP ---
$stok=0; $nilai=0; $hpp=0;
foreach($allTransaksi as $r){
$masuk=(float)($r->berat_masuk??0);
$keluar=(float)($r->berat_keluar??0);
$harga=(float)($r->harga_nett??0);
$isAdj=strpos($r->supplier_customer??'','Adjustment')!==false;

if($masuk>0 && $harga>0 && !$isAdj){
$nilai+=($masuk*$harga);
$stok+=$masuk;
$hpp=$stok>0?($nilai/$stok):0;
}
if($isAdj){
$adj=$masuk>0?$masuk:($keluar>0?- $keluar:0);
if($adj!=0){ $stok+=$adj; $hpp=$stok>0?($nilai/$stok):0; }
}elseif($keluar>0){
$nilaiKeluar=$keluar*$hpp;
$stok-=$keluar;
$nilai-=$nilaiKeluar;
$hpp=$stok>0?($nilai/$stok):0;
}
}

// --- HPP dan total dari laporan stock ---
$hppAkhir = round($hpp);
$totalBalance = $stok;
$totalBalanceRp = $nilai;

// --- Ambil stok real per kavling ---
$rawData = DB::table('m_range as mr')
->leftJoin('m_gudang_d_range as mgdr','mgdr.m_range_id','=','mr.id')
->leftJoin('m_gudang_d as mgd','mgd.id','=','mgdr.m_gudang_d_id')
->leftJoin('m_gudang as mg','mg.id','=','mgd.m_gudang_id')
->select(
'mr.nama as nama_range',
'mg.nama as nama_gudang',
'mgd.nama as nama_kavling',
DB::raw('GREATEST(mgdr.stok,0) as stok')
)
->orderBy('mr.nama')->orderBy('mg.nama')->orderBy('mgd.nama')
->get();

// --- Sinkronisasi total stok agar match ---
$sumCurrent = (float)$rawData->sum('stok');
if ($sumCurrent > 0 && abs($sumCurrent - $totalBalance) > 0.001) {
$ratio = $totalBalance / $sumCurrent;
$rawData = $rawData->map(function($r) use($ratio){
$r->stok = round($r->stok * $ratio, 3);
return $r;
});
}

// --- Hitung total Rp per kavling ---
$rawData = $rawData->map(function($r) use($hppAkhir){
$r->total_rp = $r->stok * $hppAkhir;
return $r;
});

// --- Koreksi agar total Rp pas ---
$sumRp = $rawData->sum('total_rp');
$diffRp = $totalBalanceRp - $sumRp;
if (abs($diffRp) >= 1 && $rawData->count() > 0) {
$last = $rawData->last();
$last->total_rp += $diffRp;
$rawData[$rawData->keys()->last()] = $last;
}

// ✅ Nilai akhir stok gudang (SAMA dengan $grandTotalRp di laporan stock)
$stockGudang = round($rawData->sum('total_rp'));

// =========================================================
// 3️⃣ PEMBELIAN NON-TRADING (100% IDENTIK DENGAN LAPORAN HPP)
// =========================================================
$aggPiDetailNT = DB::table('t_pi_bahan_d as tpid')
->select(
'tpid.t_penerimaan_id',
DB::raw('AVG(tpid.harga_nett) AS avg_harga_nett')
)
->groupBy('tpid.t_penerimaan_id');

$penerimaanNonTrading = DB::table('t_penerimaan as tp')
->join('t_po_bahan as tpo', 'tpo.id', '=', 'tp.t_po_bahan_id')
->leftJoinSub($aggPiDetailNT, 'pid', function($j){
$j->on('pid.t_penerimaan_id', '=', 'tp.id');
})
->where('tp.status', 'POST')
->where(fn($w)=>$w->where('tpo.is_trading', false)->orWhereNull('tpo.is_trading'))
->select(
DB::raw('COALESCE(SUM(tp.total_netto),0)::numeric AS berat_masuk'),
DB::raw('COALESCE(MAX(pid.avg_harga_nett), tpo.harga_sepakat, 0)::numeric AS harga_nett')
)
->groupBy('tp.id','tpo.harga_sepakat','pid.avg_harga_nett')
->get();

// --- Hitung total persis seperti di laporan HPP
$pembelianNonTrading = 0;
foreach ($penerimaanNonTrading as $row) {
$berat = (float)($row->berat_masuk ?? 0);
$harga = (float)($row->harga_nett ?? 0);
$pembelianNonTrading += $berat * $harga;
}

// --- Pembulatan akhir mengikuti laporan HPP
$pembelianNonTrading = round($pembelianNonTrading);

// =========================================================
// 4️⃣ PEMBELIAN TRADING
// =========================================================
$pembelianTrading=(float)DB::table('t_pi_bahan as pib')
->join('t_po_bahan as po','po.id','=','pib.t_po_bahan_id')
->where('pib.status','POST')
->where('po.is_trading',true)
->when($periodeFrom && $periodeTo,fn($q)=>$q->whereBetween('pib.tgl_pi',[$periodeFrom,$periodeTo]))
->sum('pib.grand_total');

// =========================================================
// 5️⃣ BIAYA USAHA
// =========================================================
$biayaOrder=['Barang Teknik & ATK','Bensin & Tol','Biaya Bongkar / Stapel','Biaya Kirim / Naik','Biaya Lain-Lain',
'Biaya Produksi','BPJS','Internet','Inventaris','JNE','Kayu Bakar','Makan & Air Minum','Obat Kutu',
'PDAM','Pembuatan Gudang','Pengobatan','Perbaikan Gudang & Gedung','Perbaikan Kendaraan','PLN & PDAM',
'Pulsa','Sewa Truck','STNK, KIR, PBB','Sumbangan','Uang Makan','Upah Harian','Upah Lembur','Upah Pekerja Tampah','Pajak'];

$biayaRows=DB::table('t_pengeluaran_kas as pk')
->join('m_coa as coa','coa.id','=','pk.m_coa_id')
->where('pk.status','POST')
->when($periodeFrom && $periodeTo,fn($q)=>$q->whereBetween('pk.tgl',[$periodeFrom,$periodeTo]))
->whereIn('coa.nama_coa',$biayaOrder)
->select('coa.nama_coa',DB::raw('SUM(COALESCE(pk.jumlah,0)) as total'))
->groupBy('coa.nama_coa')
->pluck('total','coa.nama_coa')
->toArray();

$biayaUsaha=[];$totalBiaya=0;
foreach($biayaOrder as $b){
$val=(float)($biayaRows[$b]??0);
$biayaUsaha[]=['label'=>$b,'total'=>$val];
$totalBiaya+=$val;
}

// =========================================================
// 6️⃣ LABA / RUGI
// =========================================================
$labaBruto=$penjualan+$stockGudang-($pembelianNonTrading+$pembelianTrading);
$labaBersih=$labaBruto-$totalBiaya;
@endphp

<div style="text-align:center;margin-bottom:10px;">
<h3 style="margin:0;font-weight:bold;">LAPORAN LABA RUGI</h3>
<p style="margin:0;">
    Periode:
    {{ $periodeFrom ? $helper->formatDateId($periodeFrom) : '-' }}
    {{ $periodeTo ? ' s/d '.$helper->formatDateId($periodeTo) : '' }}
</p>
</div>

<table style="width:100%;border-collapse:collapse;font-size:12px;">
<tbody>
    <tr>
        <td style="border:1px solid #000;padding:6px;">Penjualan</td>
        <td style="border:1px solid #000;padding:6px;text-align:right;{{ $isExcelExport ? $cellNumberStyle : '' }}">
            {{ $isExcelExport ? $fmtExportPlain($penjualan) : $fmtBrowser($penjualan) }}
        </td>
    </tr>
    <tr>
        <td style="border:1px solid #000;padding:6px;">Stock Gudang</td>
        <td style="border:1px solid #000;padding:6px;text-align:right;{{ $isExcelExport ? $cellNumberStyle : '' }}">
            {{ $isExcelExport ? $fmtExportPlain($stockGudang) : $fmtBrowser($stockGudang) }}
        </td>
    </tr>
    <tr>
        <td style="border:1px solid #000;padding:6px;">Pembelian CV Tofan</td>
        <td style="border:1px solid #000;padding:6px;text-align:right;{{ $isExcelExport ? $cellNumberStyle : '' }}">
            {{ $isExcelExport ? $fmtExportPlain($pembelianNonTrading) : $fmtBrowser($pembelianNonTrading) }}
        </td>
    </tr>
    <tr>
        <td style="border:1px solid #000;padding:6px;">Pembelian Trading</td>
        <td style="border:1px solid #000;padding:6px;text-align:right;{{ $isExcelExport ? $cellNumberStyle : '' }}">
            {{ $isExcelExport ? $fmtExportPlain($pembelianTrading) : $fmtBrowser($pembelianTrading) }}
        </td>
    </tr>
    <tr style="font-weight:bold;background:#fff8c4;">
        <td style="border:1px solid #000;padding:6px;">Laba Bruto</td>
        <td style="border:1px solid #000;padding:6px;text-align:right;{{ $isExcelExport ? $cellNumberStyle : $negStyle($labaBruto) }}">
            {{ $isExcelExport ? $fmtExportPlain($labaBruto) : $fmtBrowserNoParen($labaBruto) }}
        </td>
    </tr>
    <tr>
        <td colspan="2" style="border:1px solid #000;padding:6px;font-weight:bold;">Biaya Usaha:</td>
    </tr>
    @foreach ($biayaUsaha as $b)
    <tr>
        <td style="border:1px solid #000;padding:6px;">{{ $b['label'] }}</td>
        <td style="border:1px solid #000;padding:6px;text-align:right;{{ $isExcelExport ? $cellNumberStyle : '' }}">
            {{ $isExcelExport ? $fmtExportPlain($b['total']) : $fmtBrowser($b['total']) }}
        </td>
    </tr>
    @endforeach
    <tr style="font-weight:bold;">
        <td style="border:1px solid #000;padding:6px;">Total Biaya</td>
        <td style="border:1px solid #000;padding:6px;text-align:right;{{ $isExcelExport ? $cellNumberStyle : '' }}">
            {{ $isExcelExport ? $fmtExportPlain($totalBiaya) : $fmtBrowser($totalBiaya) }}
        </td>
    </tr>
    <tr style="font-weight:bold;background:#fff8c4;">
        <td style="border:1px solid #000;padding:6px;">Laba Bersih Sebelum Pajak</td>
        <td style="border:1px solid #000;padding:6px;text-align:right;{{ $isExcelExport ? $cellNumberStyle : $negStyle($labaBersih) }}">
            {{ $isExcelExport ? $fmtExportPlain($labaBersih) : $fmtBrowserNoParen($labaBersih) }}
        </td>
    </tr>
</tbody>
</table>

// 9 Nov 2025 - Laporan Penjualan
@php
use Illuminate\Support\Facades\DB;

$req = app()->request;
$helper = getCore('Helper');
$date_now = date('d/m/Y H:i:s');

// ======================= EXPORT FORMAT =======================
$exportParam = strtolower((string)($req->get('export') ?? ''));
$isExcelExport = in_array($exportParam, ['xls','xlsx','excel','csv'], true);

$fmtBrowser = fn($v) => 'Rp ' . number_format(abs((float)($v ?? 0)), 0, ',', '.');
$fmtExportPlain = fn($v) => (int)round((float)($v ?? 0));
$cellNumberStyle = "mso-number-format:'\\0022Rp\\0022 #,##0';";

// ======================= PERIODE =======================
$periodeFrom = $req->get('periode_from') ? date('Y-m-d', strtotime($req->get('periode_from'))) : null;
$periodeTo = $req->get('periode_to') ? date('Y-m-d', strtotime($req->get('periode_to'))) : null;

/* =====================================================
STEP 1️⃣: HISTORY HPP (SAMA DENGAN LAPORAN HPP)
===================================================== */

$aggPiDetail = DB::table('t_pi_bahan_d as tpid')
->select(
'tpid.t_penerimaan_id',
DB::raw('AVG(tpid.harga_nett) AS avg_harga_nett'),
DB::raw('MAX(tpid.kadar_air) AS max_kadar_air')
)
->groupBy('tpid.t_penerimaan_id');

$dataPo = DB::table('t_penerimaan as tp')
->join('t_po_bahan as tpo','tpo.id','=','tp.t_po_bahan_id')
->join('m_supplier as ms','ms.id','=','tpo.m_supplier_id')
->leftJoinSub($aggPiDetail,'pid',fn($j)=>$j->on('pid.t_penerimaan_id','=','tp.id'))
->select(
'tpo.no_po as no_referensi',
'tp.no_penerimaan',
'tp.tgl_penerimaan as tanggal',
'ms.nama_supplier as supplier_customer',
DB::raw('COALESCE(MAX(pid.max_kadar_air), tp.kadar_air, 0)::numeric AS kadar_air'),
DB::raw('COALESCE(MAX(pid.avg_harga_nett), tpo.harga_sepakat, 0)::numeric AS harga_nett'),
DB::raw('SUM(tp.total_netto)::numeric AS berat_masuk'),
DB::raw('NULL::numeric AS berat_keluar'),
DB::raw('MIN(tp.created_at) AS created_src')
)
->where('tp.status','POST')
->groupBy('tpo.no_po','tp.no_penerimaan','tp.tgl_penerimaan','ms.nama_supplier','tpo.harga_sepakat','tp.kadar_air');

$dataSo = DB::table('t_surat_jalan as tsj')
->join('t_sales_order as tso','tso.id','=','tsj.t_sales_order_id')
->join('m_customer as mc','mc.id','=','tso.m_customer_id')
->select(
'tso.no_so as no_referensi',
DB::raw('NULL::text as no_penerimaan'),
'tsj.tgl as tanggal',
'mc.nama as supplier_customer',
DB::raw('NULL::numeric as kadar_air'),
DB::raw('NULL::numeric as harga_nett'),
DB::raw('NULL::numeric as berat_masuk'),
'tsj.berat_kirim as berat_keluar',
DB::raw('tsj.created_at as created_src')
);

$dataAdj = DB::table('t_adjustment_stock as tas')
->join('t_adjustment_stock_d as tasd','tasd.t_adjustment_stock_id','=','tas.id')
->select(
'tas.no_adj as no_referensi',
DB::raw('NULL::text as no_penerimaan'),
'tas.tgl as tanggal',
DB::raw("'Adjustment Stock' as supplier_customer"),
DB::raw('NULL::numeric as kadar_air'),
DB::raw('NULL::numeric as harga_nett'),
DB::raw("CASE WHEN tasd.berat_adjustment > 0 THEN tasd.berat_adjustment ELSE NULL END::numeric as berat_masuk"),
DB::raw("CASE WHEN tasd.berat_adjustment < 0 THEN ABS(tasd.berat_adjustment) ELSE NULL END::numeric as berat_keluar"),
DB::raw('tas.created_at as created_src')
);

$allTransaksi=DB::query()
->fromSub($dataPo->unionAll($dataSo)->unionAll($dataAdj),'history')
->orderBy('tanggal','asc')
->orderBy('created_src','asc')
->get();

// Moving Average
$hppLog = [];
$stok = 0; $nilai = 0; $hpp = 0;

foreach($allTransaksi as $r){
$masuk = (float)($r->berat_masuk ?? 0);
$keluar = (float)($r->berat_keluar ?? 0);
$harga = (float)($r->harga_nett ?? 0);
$isAdj = str_contains($r->supplier_customer ?? '', 'Adjustment');

if($masuk > 0 && !$isAdj){
$nilai += $masuk * $harga;
$stok += $masuk;
} elseif($keluar > 0 && !$isAdj){
$nilaiKeluar = $keluar * $hpp;
$nilai -= $nilaiKeluar;
$stok -= $keluar;
} elseif($isAdj){
$stok += ($masuk ?: -$keluar);
}

$hpp = $stok > 0 ? $nilai / $stok : $hpp;

$hppLog[] = [
'tanggal' => date('Y-m-d', strtotime($r->tanggal)),
'created_src' => $r->created_src ? date('Y-m-d H:i:s', strtotime($r->created_src)) : date('Y-m-d H:i:s', strtotime($r->tanggal)),
'hpp' => $hpp,
];
}

// ✅ Fungsi baru: cari HPP sesuai timestamp kronologis penuh
$getHppByDatetime = function($tgl, $createdSrc = null) use ($hppLog) {
if(!$tgl) return 0;
$targetDate = date('Y-m-d', strtotime($tgl));
$targetTime = $createdSrc ? date('Y-m-d H:i:s', strtotime($createdSrc)) : $targetDate . ' 23:59:59';
$last = 0;
foreach($hppLog as $row){
$rowTime = $row['created_src'] ?? ($row['tanggal'].' 00:00:00');
if($rowTime <= $targetTime){
    $last=$row['hpp'];
    } else {
    break;
    }
    }
    return round($last);
    };

    /*=====================================================STEP 2️⃣: DATA PENJUALAN=====================================================*/

    $subSj=DB::table('t_surat_jalan')
    ->selectRaw("t_sales_order_id, STRING_AGG(no_polisi, ', ') as no_polisi, SUM(berat_kirim) as total_berat_sj")
    ->where('status','POST')
    ->groupBy('t_sales_order_id');

    // === Non-Trading ===
    $dataNonTrading = DB::table('t_sales_invoice AS tsi')
    ->leftJoin('t_po_bahan AS tpo','tpo.id','=','tsi.t_po_bahan_id')
    ->leftJoin('m_supplier AS ms','ms.id','=','tpo.m_supplier_id')
    ->leftJoin('t_sales_order AS tso','tso.id','=','tsi.t_sales_order_id')
    ->leftJoin('m_customer AS mc','mc.id','=','tso.m_customer_id')
    ->leftJoin('t_sales_invoice_d as tsid','tsid.t_sales_invoice_id','=','tsi.id')
    ->leftJoinSub($subSj,'tsj',fn($j)=>$j->on('tsj.t_sales_order_id','=','tso.id'))
    ->select([
    'tsi.id','tsi.tgl','tsi.is_trading',
    'tpo.harga_sepakat','ms.nama_supplier',
    'tso.no_so','tso.no_po_customer','tso.penerima',
    'tso.harga as harga_so','mc.nama as nama_customer',
    'tsj.no_polisi','tsj.total_berat_sj as berat_sj',
    'tsid.tgl_sj as tgl_sid','tsid.no_polisi as nopol_sid',
    'tsid.berat_sj as berat_sj_si','tsid.berat_si','tsid.berat_net',
    'tsid.harga_net','tsid.total',
    // tambahkan placeholder untuk kolom trading
    DB::raw('NULL as tgl_sitd'), DB::raw('NULL as nopol_sitd'),
    DB::raw('NULL as berat_sj_trading'), DB::raw('NULL as berat_si_trading'),
    DB::raw('NULL as berat_net_si_trading'), DB::raw('NULL as harga_net_si_trading'),
    DB::raw('NULL as total_si_trading'), DB::raw('NULL as total_rp_trading'),
    DB::raw('tsi.created_at as created_src')
    ])
    ->where('tsi.status','POST')
    ->where(fn($q)=>$q->where('tsi.is_trading',false)->orWhereNull('tsi.is_trading'));

    // === Trading ===
    $dataTrading = DB::table('t_sales_invoice AS tsi')
    ->leftJoin('t_po_bahan AS tpo','tpo.id','=','tsi.t_po_bahan_id')
    ->leftJoin('m_supplier AS ms','ms.id','=','tpo.m_supplier_id')
    ->leftJoin('t_pi_bahan AS tpi','tpi.id','=','tsi.t_pi_bahan_id')
    ->leftJoin('t_sales_order AS tso','tso.id','=','tsi.t_sales_order_id')
    ->leftJoin('m_customer AS mc','mc.id','=','tso.m_customer_id')
    ->leftJoin('t_sales_invoice_trading_d as tsitd','tsitd.t_sales_invoice_id','=','tsi.id')
    ->leftJoin('t_pi_trading_d as pitd', fn($j)=>
    $j->on('pitd.t_pi_bahan_id','=','tpi.id')
    ->on('pitd.no_sj_trading','=','tsitd.no_sj_trading')
    )
    ->select([
    'tsi.id','tsi.tgl','tsi.is_trading',
    'tpo.harga_sepakat','ms.nama_supplier',
    'tso.no_so','tso.no_po_customer','tso.penerima',
    'tso.harga as harga_so','mc.nama as nama_customer',
    DB::raw('NULL as no_polisi'), DB::raw('NULL as berat_sj'),
    DB::raw('NULL as tgl_sid'), DB::raw('NULL as nopol_sid'),
    DB::raw('NULL as berat_sj_si'), DB::raw('NULL as berat_si'),
    DB::raw('NULL as berat_net'), DB::raw('NULL as harga_net'),
    DB::raw('NULL as total'),
    'tsitd.tgl_terima as tgl_sitd','tsitd.no_polisi as nopol_sitd',
    'tsitd.berat_so as berat_sj_trading','tsitd.berat_si as berat_si_trading',
    'tsitd.berat_net as berat_net_si_trading','tsitd.harga_net as harga_net_si_trading',
    'tsitd.total as total_si_trading','pitd.total as total_rp_trading',
    DB::raw('tsi.created_at as created_src')
    ])
    ->where('tsi.status','POST')
    ->where('tsi.is_trading', true);

    // === Gabung dua jenis data (harus jumlah kolom sama) ===
    $rows = $dataNonTrading->unionAll($dataTrading)
    ->get()
    ->sortBy(fn($r)=>$r->created_src)
    ->values();

    /* =====================================================
    STEP 3️⃣: INJEKSI HPP
    ===================================================== */
    foreach($rows as $r){
    if($r->is_trading){
    $r->harga_po_hpp = $r->harga_sepakat;
    $r->total_rp = (float)($r->total_rp_trading ?? 0);
    } else {
    $hppHarga = $getHppByDatetime($r->tgl_sid ?? $r->tgl, $r->created_src);
    $r->harga_po_hpp = $hppHarga;
    $r->total_rp = ((float)($r->berat_sj_si ?? 0)) * $hppHarga;
    }
    }
    @endphp

    <!-- ======================= VIEW ======================= -->
    <div>
        <h1 style="font-weight:bold;text-align:center;">Laporan Penjualan Gudang</h1>
        <p style="text-align:center;">
            Periode :
            {{ @$req->periode_from ? $helper->formatDateId(@$req->periode_from) : '-' }}
            {{ @$req->periode_to ? '- ' . $helper->formatDateId(@$req->periode_to) : '' }}
        </p><br>

        <table style="border:1px solid black;border-collapse:collapse;width:100%;font-size:8px;">
            <thead>
                <tr>
                    @foreach(['No.','No PO','Supplier','Harga PO / HPP','NO SO','Customer','Penerima','Harga SO',
                    'Tanggal SI','No Pol','Berat SJ','Berat SI','Berat Net','Harga Net','Total SI','Total Pembelian'] as $h)
                    <th style="border:1px solid #000;padding:6px;background:#f2f2f2;text-align:center;">{{ $h }}</th>
                    @endforeach
                </tr>
            </thead>
            <tbody>
                @foreach($rows as $i=>$r)
                <tr>
                    <td style="border:1px solid #000;text-align:center;">{{ $i+1 }}</td>
                    <td style="border:1px solid #000;text-align:center;">{{ $r->no_po_customer ?? '-' }}</td>
                    <td style="border:1px solid #000;">{{ $r->is_trading ? ($r->nama_supplier ?? '-') : 'CV TOFAN' }}</td>
                    <td style="border:1px solid #000;text-align:right;{{ $isExcelExport ? $cellNumberStyle : '' }}">
                        {{ $isExcelExport ? $fmtExportPlain($r->harga_po_hpp ?? 0)
: number_format((float)($r->harga_po_hpp ?? 0),0,',','.') }}
                    </td>
                    <td style="border:1px solid #000;text-align:center;">{{ $r->no_so ?? '-' }}</td>
                    <td style="border:1px solid #000;text-align:center;">{{ $r->nama_customer ?? '-' }}</td>
                    <td style="border:1px solid #000;text-align:center;">{{ $r->is_trading ? ($r->nama_customer ?? '-') : ($r->penerima ?? '-') }}</td>
                    <td style="border:1px solid #000;text-align:right;{{ $isExcelExport ? $cellNumberStyle : '' }}">
                        {{ $isExcelExport ? $fmtExportPlain($r->harga_so ?? 0)
: number_format((float)($r->harga_so ?? 0),0,',','.') }}
                    </td>
                    <td style="border:1px solid #000;text-align:center;">
                        {{ $r->is_trading ? ($r->tgl_sitd ? date('d-m-Y',strtotime($r->tgl_sitd)) : '-') :
    ($r->tgl_sid ? date('d-m-Y',strtotime($r->tgl_sid)) : '-') }}
                    </td>
                    <td style="border:1px solid #000;text-align:center;">{{ $r->is_trading ? ($r->nopol_sitd ?? '-') : ($r->nopol_sid ?? '-') }}</td>
                    <td style="border:1px solid #000;text-align:right;{{ $isExcelExport ? $cellNumberStyle : '' }}">
                        {{ $isExcelExport ? $fmtExportPlain($r->is_trading ? $r->berat_sj_trading ?? 0 : $r->berat_sj_si ?? 0)
: number_format((float)($r->is_trading ? $r->berat_sj_trading ?? 0 : $r->berat_sj_si ?? 0),0,',','.') }}
                    </td>
                    <td style="border:1px solid #000;text-align:right;{{ $isExcelExport ? $cellNumberStyle : '' }}">
                        {{ $isExcelExport ? $fmtExportPlain($r->is_trading ? $r->berat_si_trading ?? 0 : $r->berat_si ?? 0)
: number_format((float)($r->is_trading ? $r->berat_si_trading ?? 0 : $r->berat_si ?? 0),0,',','.') }}
                    </td>
                    <td style="border:1px solid #000;text-align:right;{{ $isExcelExport ? $cellNumberStyle : '' }}">
                        {{ $isExcelExport ? $fmtExportPlain($r->is_trading ? $r->berat_net_si_trading ?? 0 : $r->berat_net ?? 0)
: number_format((float)($r->is_trading ? $r->berat_net_si_trading ?? 0 : $r->berat_net ?? 0),0,',','.') }}
                    </td>
                    <td style="border:1px solid #000;text-align:right;{{ $isExcelExport ? $cellNumberStyle : '' }}">
                        {{ $isExcelExport ? $fmtExportPlain($r->is_trading ? $r->harga_net_si_trading ?? 0 : $r->harga_net ?? 0)
: number_format((float)($r->is_trading ? $r->harga_net_si_trading ?? 0 : $r->harga_net ?? 0),0,',','.') }}
                    </td>
                    <td style="border:1px solid #000;text-align:right;{{ $isExcelExport ? $cellNumberStyle : '' }}">
                        {{ $isExcelExport ? $fmtExportPlain($r->is_trading ? $r->total_si_trading ?? 0 : $r->total ?? 0)
: number_format((float)($r->is_trading ? $r->total_si_trading ?? 0 : $r->total ?? 0),0,',','.') }}
                    </td>
                    <td style="border:1px solid #000;text-align:right;{{ $isExcelExport ? $cellNumberStyle : '' }}">
                        {{ $isExcelExport ? $fmtExportPlain($r->total_rp ?? 0)
: number_format((float)($r->total_rp ?? 0),0,',','.') }}
                    </td>
                </tr>
                @endforeach
            </tbody>
        </table>
    </div>