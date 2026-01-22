// laporan penjualan
@php
$req = app()->request;
$helper = getCore('Helper');
$date_now = date('d/m/Y H:i:s');

// Subquery HPP Global
$subHpp = \DB::table('t_penerimaan as tp')
->leftJoin('t_po_bahan as tpo', 'tp.t_po_bahan_id', '=', 'tpo.id')
->selectRaw('
COALESCE(SUM(tp.total_netto * tpo.harga_sepakat),0) as total_nilai,
COALESCE(SUM(tp.total_netto),0) as total_qty
');

// Ambil Sales Invoice + HPP
$data = \DB::table('t_sales_invoice as tsi')
->leftJoin('t_po_bahan as tpo', 'tpo.id', '=', 'tsi.t_po_bahan_id')
->leftJoin('m_supplier as ms', 'ms.id', '=', 'tpo.m_supplier_id')
->leftJoin('t_pi_bahan as tpi', 'tpi.t_po_bahan_id', '=', 'tpo.id')
->leftJoin('t_pi_trading_d as tpitd', 'tpitd.t_pi_bahan_id', '=', 'tpi.id')
->leftJoin('t_sales_order as tso', 'tso.id', '=', 'tsi.t_sales_order_id')
->leftJoin('m_customer as mc', 'mc.id', '=', 'tso.m_customer_id')
//->leftJoin('t_surat_jalan as tsj', 'tsj.t_sales_order_id', '=', 'tso.id')
->leftJoin('t_surat_jalan as tsj', function($join){
$join->on('tsj.t_sales_order_id', '=', 'tso.id')
->where('tsj.status', '=', 'POST');
})
->leftJoin('t_sales_invoice_d as tsid', 'tsid.t_sales_invoice_id', '=', 'tsi.id')
->leftJoin('t_sales_invoice_trading_d as tsitd', 'tsitd.t_sales_invoice_id', '=', 'tsi.id')
->crossJoinSub($subHpp, 'hpp') // cross join supaya dapat nilai global
->select([
'tsi.id',
'tsi.tgl as tgl_si',
'tsi.is_trading',
'tpo.harga_sepakat',
'ms.nama_supplier as nama_supplier',
'tso.no_so',
'tso.no_po_customer',
'tso.penerima',
'tso.harga as harga_so',
'mc.nama as nama_customer',
'tsj.no_polisi',
'tsj.berat_kirim as berat_sj',

// non trading
'tsid.berat_si',
'tsid.berat_net as berat_net_si',
'tsid.harga_net as harga_net_si',
'tsid.total as total_si',
// total pembelian non trading

// trading
'tsitd.berat_so as berat_sj_trading',
'tsitd.berat_si as berat_si_trading',
'tsitd.berat_net as berat_net_si_trading',
'tsitd.harga_net as harga_net_si_trading',
'tsitd.no_polisi as no_polisi_trading',
'tsitd.total as total_si_trading',
// total pembelian trading
//'tpitd.total as total_beli_trading'

// HPP global (harga_po_hpp)
\DB::raw('CASE WHEN hpp.total_qty > 0
THEN ROUND(hpp.total_nilai / hpp.total_qty,2)
ELSE 0 END as harga_po_hpp'),

// ✅ Total pembelian non trading = HPP * berat SJ
\DB::raw('
CASE WHEN hpp.total_qty > 0
THEN ROUND(COALESCE(tsj.berat_kirim,0) * (hpp.total_nilai / hpp.total_qty),2)
ELSE 0 END as total_rp
'),

// ✅ Total pembelian trading = (berat net * harga net)
\DB::raw('
ROUND(COALESCE(tpitd.berat_net,0) * COALESCE(tpitd.harga_net,0),2) as total_rp_trading
')

])
->when($req->periode_from, function ($q) use ($req) {
return $q->whereDate('tsi.tgl', '>=', date('Y-m-d', strtotime($req->periode_from)));
})
->when($req->periode_to, function ($q) use ($req) {
return $q->whereDate('tsi.tgl', '<=', date('Y-m-d', strtotime($req->periode_to)));
})
->where('tsi.status', '=', 'POST')
->orderBy('tsi.created_at', 'asc')
->get();
@endphp

// laporan penjualan HPP (berubah setiap SJ - tofan)
@php
$req = app()->request;
$helper = getCore('Helper');
$date_now = date('d/m/Y H:i:s');

// --- Subquery: Non-trading Invoice Detail ---
$subSiDetail = \DB::table('t_sales_invoice_d')
->selectRaw('t_sales_invoice_id,
SUM(berat_si) as berat_si,
SUM(berat_net) as berat_net_si,
AVG(harga_net) as harga_net_si,
SUM(total) as total_si')
->groupBy('t_sales_invoice_id');

// --- Subquery: Trading Invoice Detail ---
$subSiTrading = \DB::table('t_sales_invoice_trading_d')
->selectRaw("t_sales_invoice_id,
SUM(berat_so) as berat_sj_trading,
SUM(berat_si) as berat_si_trading,
SUM(berat_net) as berat_net_si_trading,
AVG(harga_net) as harga_net_si_trading,
STRING_AGG(no_polisi, ', ') as no_polisi_trading,
SUM(total) as total_si_trading")
->groupBy('t_sales_invoice_id');

// --- Subquery: Surat Jalan per Sales Order ---
$subSj = \DB::table('t_surat_jalan')
->selectRaw("t_sales_order_id,
STRING_AGG(no_polisi, ', ') as no_polisi,
SUM(berat_kirim) as total_berat_sj")
->where('status','=','POST')
->groupBy('t_sales_order_id');

// --- Query utama: Invoice ---
$data = \DB::table('t_sales_invoice as tsi')
->leftJoin('t_po_bahan as tpo', 'tpo.id', '=', 'tsi.t_po_bahan_id')
->leftJoin('m_supplier as ms', 'ms.id', '=', 'tpo.m_supplier_id')
->leftJoin('t_pi_bahan as tpi', 'tpi.id', '=', 'tsi.t_pi_bahan_id')
->leftJoin('t_pi_trading_d as tpitd', 'tpitd.t_pi_bahan_id', '=', 'tpi.id')
->leftJoin('t_sales_order as tso', 'tso.id', '=', 'tsi.t_sales_order_id')
->leftJoin('m_customer as mc', 'mc.id', '=', 'tso.m_customer_id')
->leftJoinSub($subSiDetail, 'tsid', function($join){
$join->on('tsid.t_sales_invoice_id','=','tsi.id');
})
->leftJoinSub($subSiTrading, 'tsitd', function($join){
$join->on('tsitd.t_sales_invoice_id','=','tsi.id');
})
->leftJoinSub($subSj, 'tsj', function($join){
$join->on('tsj.t_sales_order_id','=','tso.id');
})
// 🚀 HPP dinamis per tanggal pakai LATERAL
->join(DB::raw("
LATERAL (
SELECT
SUM(tp.total_netto * tpo2.harga_sepakat) / NULLIF(SUM(tp.total_netto),0) as hpp_harga
FROM t_penerimaan tp
LEFT JOIN t_po_bahan tpo2 ON tp.t_po_bahan_id = tpo2.id
WHERE tp.tgl_penerimaan <= tsi.tgl
) hpp "), \DB::raw('1'), '=', \DB::raw('1'))
->select([
'tsi.id',
'tsi.tgl as tgl_si',
'tsi.is_trading',
'tpo.harga_sepakat',
'ms.nama_supplier as nama_supplier',
'tso.no_so',
'tso.no_po_customer',
'tso.penerima',
'tso.harga as harga_so',
'mc.nama as nama_customer',

// dari aggregate SJ
'tsj.no_polisi',
'tsj.total_berat_sj as berat_sj',

// non trading
'tsid.berat_si',
'tsid.berat_net_si',
'tsid.harga_net_si',
'tsid.total_si',

// trading
'tsitd.berat_sj_trading',
'tsitd.berat_si_trading',
'tsitd.berat_net_si_trading',
'tsitd.harga_net_si_trading',
'tsitd.no_polisi_trading',
'tsitd.total_si_trading',

// ✅ HPP dinamis
\DB::raw(" ROUND(COALESCE(hpp.hpp_harga,0),2) as harga_po_hpp"),

// ✅ Total pembelian non trading
\DB::raw("ROUND(COALESCE(tsj.total_berat_sj,0) * COALESCE(hpp.hpp_harga,0),2) as total_rp"),

// ✅ Total pembelian trading
//\DB::raw("ROUND(COALESCE(tsitd.berat_net_si_trading,0) * COALESCE(tsitd.harga_net_si_trading,0),2) as total_rp_trading")
\DB::raw("ROUND(COALESCE(tpitd.total,0),2) as total_rp_trading"),
])
->when($req->periode_from, function ($q) use ($req) {
return $q->whereDate('tsi.tgl', '>=', date('Y-m-d', strtotime($req->periode_from)));
})
->when($req->periode_to, function ($q) use ($req) {
return $q->whereDate('tsi.tgl', '<=', date('Y-m-d', strtotime($req->periode_to)));
})
->where('tsi.status', '=', 'POST')
->orderBy('tsi.created_at', 'asc')
->get();
@endphp



<div>
<div>
    <h1 style="border-collapse: collapse; font-weight: bold; text-align: center;">Laporan Penjualan Gudang</h1>
    <p style="text-align: center;">Periode :
        {{ @$req->periode_from ? $helper->formatDateId(@$req->periode_from) : '-' }}
        {{ @$req->periode_to ? '- ' . $helper->formatDateId(@$req->periode_to) : '' }}
    </p>
</div>
<br>

<table style="border: 1px solid black; border-collapse: collapse; width: 100%; font-size: 8px;">
    <thead>
        <tr>
            <th style="border: 1px solid #000; padding: 6px; background-color: #f2f2f2; text-align: center;">No.</th>
            <th style="border: 1px solid #000; padding: 6px; background-color: #f2f2f2; text-align: center;">No PO</th>
            <th style="border: 1px solid #000; padding: 6px; background-color: #f2f2f2; text-align: center;">Supplier</th>
            <th style="border: 1px solid #000; padding: 6px; background-color: #f2f2f2; text-align: center;">Harga PO / HPP</th>
            <th style="border: 1px solid #000; padding: 6px; background-color: #f2f2f2; text-align: center;">NO SO</th>
            <th style="border: 1px solid #000; padding: 6px; background-color: #f2f2f2; text-align: center;">Customer</th>
            <th style="border: 1px solid #000; padding: 6px; background-color: #f2f2f2; text-align: center;">Penerima</th>
            <th style="border: 1px solid #000; padding: 6px; background-color: #f2f2f2; text-align: center;">Harga SO</th>
            <th style="border: 1px solid #000; padding: 6px; background-color: #f2f2f2; text-align: center;">Tanggal SI</th>
            <th style="border: 1px solid #000; padding: 6px; background-color: #f2f2f2; text-align: center;">No Pol </th>
            <th style="border: 1px solid #000; padding: 6px; background-color: #f2f2f2; text-align: center;">Berat SJ</th>
            <th style="border: 1px solid #000; padding: 6px; background-color: #f2f2f2; text-align: center;">Berat SI</th>
            <th style="border: 1px solid #000; padding: 6px; background-color: #f2f2f2; text-align: center;">Berat Net</th>
            <th style="border: 1px solid #000; padding: 6px; background-color: #f2f2f2; text-align: center;">Harga Net</th>
            <th style="border: 1px solid #000; padding: 6px; background-color: #f2f2f2; text-align: center;">Total SI</th>
            <th style="border: 1px solid #000; padding: 6px; background-color: #f2f2f2; text-align: center;">Total Pembelian</th>
        </tr>
    </thead>
    <tbody>
        @foreach($data as $index => $row)
        <tr>
            <td style="border: 1px solid #000; padding: 6px; text-align: center;"> {{ $index + 1 }}</td>

            <td style="border: 1px solid #000; padding: 6px; text-align: center;"> {{ $row->no_po_customer ?? '-' }}</td>

            @if($row->is_trading === true)
            <td style="border: 1px solid #000; padding: 6px;"> {{ $row->nama_supplier }}</td>
            @else
            <td style="border: 1px solid #000; padding: 6px;"> CV TOFAN </td>
            @endif

            @if($row->is_trading === true)
            <td style="border: 1px solid #000; padding: 6px;">{{ number_format($row->harga_sepakat,0,',','.') }}</td>
            @else
            <td style="border: 1px solid #000; padding: 6px;">{{ number_format($row->harga_po_hpp,0,',','.') }}</td>
            @endif

            <td style="border: 1px solid #000; padding: 6px; text-align: center;"> {{ $row->no_so ?? '-' }} </td>

            <td style="border: 1px solid #000; padding: 6px; text-align: right;"> {{ $row->nama_customer ?? '-' }} </td>

            @if($row->is_trading === true)
            <td style="border: 1px solid #000; padding: 6px; text-align: right;"> {{ $row->nama_customer ?? '-' }} </td>
            @else
            <td style="border: 1px solid #000; padding: 6px; text-align: right;"> {{ $row->penerima ?? '-' }} </td>
            @endif

            <td style="border: 1px solid #000; padding: 6px; text-align: right;"> Rp. {{ $row->harga_so ? number_format($row->harga_so, 0, ',', '.') : '-' }} </td>

            <td style="border: 1px solid #000; padding: 6px; text-align: center;"> {{ $row->tgl_si ? date('d-m-Y', strtotime($row->tgl_si)) : '-' }} </td>

            @if($row->is_trading === true)
            <td style="border: 1px solid #000; padding: 6px; text-align: right;"> {{ $row->no_polisi_trading ?? '-' }} </td>
            @else
            <td style="border: 1px solid #000; padding: 6px; text-align: right;"> {{ $row->no_polisi ?? '-' }} </td>
            @endif

            @if($row->is_trading === true)
            <td style="border: 1px solid #000; padding: 6px; text-align: right;"> {{ $row->berat_sj_trading ? number_format($row->berat_sj_trading, 0, ',', '.') : '-' }} </td>
            @else
            <td style="border: 1px solid #000; padding: 6px; text-align: right;"> {{ $row->berat_sj ? number_format($row->berat_sj, 0, ',', '.') : '-' }} </td>
            @endif

            @if($row->is_trading === true)
            <td style="border: 1px solid #000; padding: 6px; text-align: right;"> {{ $row->berat_si_trading ? number_format($row->berat_si_trading, 0, ',', '.') : '-' }} </td>
            @else
            <td style="border: 1px solid #000; padding: 6px; text-align: right;"> {{ $row->berat_si ? number_format($row->berat_si, 0, ',', '.') : '-' }} </td>
            @endif

            @if($row->is_trading === true)
            <td style="border: 1px solid #000; padding: 6px; text-align: right;"> {{ $row->berat_net_si_trading ? number_format($row->berat_net_si_trading, 0, ',', '.') : '-' }} </td>
            @else
            <td style="border: 1px solid #000; padding: 6px; text-align: right;"> {{ $row->berat_net_si ? number_format($row->berat_net_si, 0, ',', '.') : '-' }} </td>
            @endif

            @if($row->is_trading === true)
            <td style="border: 1px solid #000; padding: 6px; text-align: right;"> {{ $row->harga_net_si_trading ? number_format($row->harga_net_si_trading, 0, ',', '.') : '-' }} </td>
            @else
            <td style="border: 1px solid #000; padding: 6px; text-align: right;"> {{ $row->harga_net_si ? number_format($row->harga_net_si, 0, ',', '.') : '-' }} </td>
            @endif

            @if($row->is_trading === true)
            <td style="border: 1px solid #000; padding: 6px; text-align: right;"> {{ $row->total_si_trading ? number_format($row->total_si_trading, 0, ',', '.') : '-' }} </td>
            @else
            <td style="border: 1px solid #000; padding: 6px; text-align: right;"> {{ $row->total_si ? number_format($row->total_si, 0, ',', '.') : '-' }} </td>
            @endif

            @if($row->is_trading === true)
            <td style="border: 1px solid #000; padding: 6px; text-align: right;">{{ number_format($row->total_rp_trading,0,',','.') }}</td>
            @else
            <td style="border: 1px solid #000; padding: 6px; text-align: right;">{{ number_format($row->total_rp,0,',','.') }}</td>
            @endif
        </tr>
        @endforeach
    </tbody>
</table>
</div>


// laporan stock hpp global
@php
$req = app()->request;
$helper = getCore('Helper');
$date_now = date('d/m/Y H:i:s');

if(@!$user && $req->u_id){
$user = \DB::table('m_user')->where('id', $req->u_id)->select('name')->first();
}

$rangeId = $req->get('range_id');

// 1. Ambil HPP GLOBAL (tanpa filter range/periode)
$allData = \DB::table('t_penerimaan as tp')
->leftJoin('t_po_bahan as tpb', 'tp.t_po_bahan_id', '=', 'tpb.id')
->selectRaw('COALESCE(SUM(tp.total_netto * tpb.harga_sepakat),0) as total_nilai')
->selectRaw('COALESCE(SUM(tp.total_netto),0) as total_qty')
->first();

$grandHpp = $allData->total_qty > 0 ? ($allData->total_nilai / $allData->total_qty) : 0;

// 2. Ambil data stok sesuai filter (range/periode)
$rawData = \DB::table('m_range as mr')
->leftJoin('m_gudang_d_range as mgdr', 'mgdr.m_range_id', '=', 'mr.id')
->leftJoin('m_gudang_d as mgd', 'mgd.id', '=', 'mgdr.m_gudang_d_id')
->leftJoin('m_gudang as mg', 'mg.id', '=', 'mgd.m_gudang_id')
->select(
'mr.nama as nama_range',
'mg.nama as nama_gudang',
'mgd.nama as nama_kavling',
\DB::raw('GREATEST(mgdr.stok, 0) as stok'),
'mgdr.updated_at'
)
->when($rangeId, function ($q) use ($rangeId) {
return $q->where('mr.id', $rangeId);
})
->when($req->periode_from, function ($q) use ($req) {
return $q->whereDate('mgdr.updated_at', '>=', date('Y-m-d', strtotime($req->periode_from)));
})
->when($req->periode_to, function ($q) use ($req) {
return $q->whereDate('mgdr.updated_at', '<=', date('Y-m-d', strtotime($req->periode_to)));
})
->orderBy('mr.nama')
->orderBy('mg.nama')
->orderBy('mgd.nama')
->get();

// 3. Hitung total Rp pakai HPP global
$data = $rawData
->map(function ($row) use ($grandHpp) {
$row->total_rp = $row->stok * $grandHpp;
$row->hpp = $grandHpp;
return $row;
})
->filter(function ($row) {
return $row->stok > 0;
})
->groupBy('nama_range')
->map(function ($items) {
return $items->groupBy('nama_gudang');
});

$grandStok = $rawData->sum('stok');
$grandTotalRp = $grandStok * $grandHpp;

function rupiah($val){
return 'Rp ' . number_format($val, 0, ',', '.');
}
@endphp

<div>
    <div>
        <h1>LAPORAN STOCK OVERVIEW</h1>
        <p>
            PERIODE {{@$req->periode_from ? $helper->formatDateId(@$req->periode_from) : '-'}}
            {{@$req->periode_to ? '- ' . $helper->formatDateId(@$req->periode_to) : ''}}
        </p>
        <p>HPP Global: <b>{{ rupiah($grandHpp) }}</b></p>
    </div>

    <table style="border: 1px solid black; border-collapse: collapse; width: 100%; font-size: 8px;">
        <thead>
            <tr>
                <th style="border: 1px solid black; text-align: center; background-color: #f2f2f2;">No.</th>
                <th style="border: 1px solid black; text-align: center; background-color: #f2f2f2;">RANGE KA</th>
                <th style="border: 1px solid black; text-align: center; background-color: #f2f2f2;">Gudang</th>
                <th style="border: 1px solid black; text-align: center; background-color: #f2f2f2;">Kavling</th>
                <th style="border: 1px solid black; text-align: center; background-color: #f2f2f2;">Balance</th>
                <th style="border: 1px solid black; text-align: center; background-color: #f2f2f2;">Total Rp</th>
            </tr>
        </thead>
        <tbody>
            @php $no = 1; @endphp
            @foreach ($data as $range => $gudangs)
            @php
            $rangeRows = $gudangs->flatten(1);
            if ($rangeRows->sum('stok') == 0) continue;
            $rangeRowspan = $rangeRows->count();
            $firstRangeRow = true;
            @endphp

            @foreach ($gudangs as $gudang => $kavlings)
            @php
            if ($kavlings->sum('stok') == 0) continue;
            $gudangRowspan = $kavlings->count();
            $firstGudangRow = true;
            @endphp

            @foreach ($kavlings as $row)
            <tr>
                @if ($firstRangeRow)
                <td rowspan="{{ $rangeRowspan }}" style="border: 1px solid black; text-align: center;">{{ $no++ }}</td>
                <td rowspan="{{ $rangeRowspan }}" style="border: 1px solid black; text-align: center;">{{ $range }}</td>
                @php $firstRangeRow = false; @endphp
                @endif

                @if ($firstGudangRow)
                <td rowspan="{{ $gudangRowspan }}" style="border: 1px solid black; text-align: center;">{{ $gudang }}</td>
                @php $firstGudangRow = false; @endphp
                @endif

                <td style="border: 1px solid black; text-align: center;">{{ $row->nama_kavling }}</td>
                <td style="border: 1px solid black; text-align: center;">{{ number_format($row->stok, 0, ',', '.') }}</td>
                <td style="border: 1px solid black; text-align: center;">{{ rupiah($row->total_rp) }}</td>
            </tr>
            @endforeach
            @endforeach
            @endforeach

            <tr style="font-weight: bold; background: #f2f2f2;">
                <td colspan="4" style="border: 1px solid black; text-align: center;">TOTAL</td>
                <td style="border: 1px solid black; text-align: center;">
                    {{ number_format($grandStok, 0, ',', '.') }}
                </td>
                <td style="border: 1px solid black; text-align: center;">
                    {{ rupiah($grandTotalRp) }}
                </td>
            </tr>

            <tr style="font-weight: bold; background: #e6ffe6;">
                <td colspan="5" style="border: 1px solid black; text-align: right;">HPP GLOBAL</td>
                <td style="border: 1px solid black; text-align: center;">
                    {{ rupiah($grandHpp) }}
                </td>
            </tr>
        </tbody>
    </table>
</div>