public function scopeHistoriSJBySO($query)
{
return $query
->from("t_sales_order")
->select(
"t_sales_order.*",
"mc.nama as nama_customer",

// SJ (only attached when there is NO trading invoice for this SO)
"t_surat_jalan.id as surat_jalan_id",
"t_surat_jalan.no_sj",
"t_surat_jalan.tgl as tgl_sj",
"t_surat_jalan.berat_kirim",

\DB::raw(
"(SELECT COUNT(*) FROM t_sales_invoice si2 WHERE si2.t_sales_order_id = t_sales_order.id AND si2.status = 'POST') AS jumlah_si"
),

// invoice (generic post invoices)
"si.id as sales_invoice_id",
"si.no_si",
"si.is_trading",

// non trading → invoice detail (linked to sj)
"sid.id as si_d_id",
"sid.ka as kadar_air",
"sid.berat_si",
"sid.harga_net as harga_net",

// trading
"sitd.id as si_trading_d_id",
"sitd.no_sj_trading",
"t_pi_bahan.tgl_pi as tgl_pi_trading",
"sitd.ka as kadar_air_trading",
"sitd.berat_si as berat_si_trading",
"sitd.harga_net as harga_net_trading"
)

// customer
->leftJoin("m_customer as mc", "mc.id", "=", "t_sales_order.m_customer_id")

// join invoice (all POST invoices) — keep this to expose si.* in select
->leftJoin("t_sales_invoice as si", function ($join) {
$join->on("si.t_sales_order_id", "=", "t_sales_order.id")
->where("si.status", "=", "POST");
})

->leftJoin("t_surat_jalan", function ($join) {
$join->on("t_surat_jalan.t_sales_order_id", "=", "t_sales_order.id")
->where("t_surat_jalan.status", "=", "POST")
->whereRaw("NOT EXISTS (
SELECT 1 FROM t_sales_invoice si_tr
WHERE si_tr.t_sales_order_id = t_sales_order.id
AND si_tr.status = 'POST'
AND si_tr.is_trading = true
)");
})

// invoice detail for non-trading: link to si and to the surat_jalan row
->leftJoin("t_sales_invoice_d as sid", function ($join) {
$join->on("sid.t_sales_invoice_id", "=", "si.id")
// only non-trading invoice details (si.is_trading = false OR si IS NULL)
->where(function ($q) {
$q->where("si.is_trading", "=", false)
->orWhereNull("si.is_trading");
})
->whereColumn("sid.t_surat_jalan_id", "t_surat_jalan.id");
})

// trading invoice details
->leftJoin("t_sales_invoice_trading_d as sitd", function ($join) {
$join->on("sitd.t_sales_invoice_id", "=", "si.id")
->where("si.is_trading", "=", true);
})

// PI / PI trading (only relevant for trading)
->leftJoin("t_pi_bahan", function ($join) {
$join->on("t_pi_bahan.t_sales_order_id", "=", "t_sales_order.id")
->where("si.is_trading", "=", true);
})
->leftJoin("t_pi_trading_d", function ($join) {
$join->on("t_pi_trading_d.t_pi_bahan_id", "=", "t_pi_bahan.id")
->where("si.is_trading", "=", true);
})

->groupBy(
"t_sales_order.id",
"mc.nama",

// SJ
"t_surat_jalan.id",
"t_surat_jalan.no_sj",
"t_surat_jalan.tgl",
"t_surat_jalan.berat_kirim",

// si
"si.id",
"si.no_si",
"si.is_trading",

// non trading (sid)
"sid.id",
"sid.ka",
"sid.berat_si",
"sid.harga_net",

// trading (sitd)
"sitd.id",
"sitd.no_sj_trading",
"sitd.ka",
"sitd.berat_si",
"sitd.harga_net",

// pi
"t_pi_bahan.tgl_pi"
)
->orderByDesc("t_sales_order.id")
->orderByDesc("t_surat_jalan.id");
}

// Query laporan history gudang
<div>
    <div>
        <h1>LAPORAN HISTORY GUDANG</h1>
        <p>PERIODE {{@$req->periode_from ? $helper->formatDateId(@$req->periode_from) : '-'}} {{@$req->periode_to ? '- ' . $helper->formatDateId(@$req->periode_to) : ''}}</p>
    </div>
    <br>
    @php
    //$id_gudang = 6;
    //$id_gudang_d = 11;
    $id_gudang = $gudangId;
    $id_gudang_d = $kavlingId;
    //$id_range = 1;

    $balance = \DB::table('m_gudang_d_range as mgdr')
    ->join('m_gudang_d as mgd', 'mgd.id', '=', 'mgdr.m_gudang_d_id')
    ->join('m_gudang as mg', 'mg.id', '=', 'mgd.m_gudang_id')
    ->where('mg.id', $id_gudang)
    ->where('mgd.id', $id_gudang_d)
    //->where('mgdr.m_range_id', $id_range) // kalau mau filter range
    ->value('mgdr.stok');
    @endphp



    <table style="border-collapse: collapse; width: 100%; font-family: Arial, sans-serif; font-size: 13px;">
        <thead>
            <tr>
                <th style="border: 1px solid #000; padding: 6px; background-color: #f2f2f2; text-align: center;">No.</th>
                <th style="border: 1px solid #000; padding: 6px; background-color: #f2f2f2; text-align: center;">Tanggal</th>
                <th style="border: 1px solid #000; padding: 6px; background-color: #f2f2f2; text-align: center;">No Referensi</th>
                <th style="border: 1px solid #000; padding: 6px; background-color: #f2f2f2; text-align: center;">Nama Supplier / Customer</th>
                <th style="border: 1px solid #000; padding: 6px; background-color: #f2f2f2; text-align: center;">KA (%)</th>
                <th style="border: 1px solid #000; padding: 6px; background-color: #f2f2f2; text-align: center;">Berat Penerimaan</th>
                <th style="border: 1px solid #000; padding: 6px; background-color: #f2f2f2; text-align: center;">Berat Surat Jalan</th>
                <th style="border: 1px solid #000; padding: 6px; background-color: #f2f2f2; text-align: center;">Balance</th>
            </tr>
        </thead>
        <tbody>
            @foreach($data as $i => $row)
            @php
            $beratMasuk = $row->berat_masuk ?? 0;
            $beratKeluar = $row->berat_keluar ?? 0;
            $balance += $beratMasuk - $beratKeluar;
            @endphp
            <tr>
                <td style="border: 1px solid #000; padding: 6px; text-align: center;">{{ $i + 1 }}</td>
                <td style="border: 1px solid #000; padding: 6px; text-align: center;">{{ \Carbon\Carbon::parse($row->tanggal)->format('d/m/Y') }}</td>
                <td style="border: 1px solid #000; padding: 6px;">{{ $row->no_referensi }}</td>
                <td style="border: 1px solid #000; padding: 6px;">{{ $row->nama_supplier_customer }}</td>
                <td style="border: 1px solid #000; padding: 6px; text-align: center;">{{ $row->kadar_air ?? '' }}</td>
                <td style="border: 1px solid #000; padding: 6px; text-align: right;">{{ $beratMasuk ? number_format($beratMasuk, 2) : '' }}</td>
                <td style="border: 1px solid #000; padding: 6px; text-align: right;">{{ $beratKeluar ? number_format($beratKeluar, 2) : '' }}</td>
                <td style="border: 1px solid #000; padding: 6px; text-align: right;">{{ number_format($balance, 2) }}</td>
            </tr>
            @endforeach

            <tr>
                <td colspan="7" style="border: 1px solid #000; padding: 6px; font-weight: bold;">Sisa Stock</td>
                <td style="border: 1px solid #000; padding: 6px; text-align: right; font-weight: bold;">{{ number_format($balance, 2) }}</td>
            </tr>
        </tbody>
    </table>
</div>