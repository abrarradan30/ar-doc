// 1. Code merubah status DRAFT ke POST
public function createBefore($model, $arrayData, $metaData, $id = null)
{
$checkDuplicate = $this->IsDuplicate($arrayData);
if($checkDuplicate) return ['errors' => ["No Penerimaan Barang / Jasa Sudah Pernah Dibuat"]];
--digunakan ketika nomer digenerate secara otomatis--

$status = "DRAFT";
$req = app()->request;
if($req->post){
$status = "POST";
}

$newData = [
"no_penerimaan" => $this->helper->generateNomor("Draft Penerimaan Barang / Jasa"),
--digunakan ketika nomer digenerate secara otomatis--
"status"=>$status
];

$newArrayData = array_merge($arrayData, $newData);
return [
"model" => $model,
"data" => $newArrayData,
// "errors" => ['error1']
];
}

public function updateBefore( $model, $arrayData, $metaData, $id=null )
{
$checkDuplicate = $this->IsDuplicate($arrayData);
if($checkDuplicate) return ['errors' => ["Data Sudah Pernah Dibuat"]];
--digunakan ketika nomer digenerate secara otomatis--

$req = app()->request;
$status = $req->post ? "POST" : $arrayData['status'];

$newData=[
"status" => $status
];
$newArrayData = array_merge( $arrayData,$newData );
return [
"model" => $model,
"data" => $newArrayData,
// "errors" => ['error1']
];
}

public function custom_post()
{
$id = request("id");
$status = $this->where("id", $id)->update(["status" => "POST"]);
return ["success" => true];
}

// 2. Code custom scope

public function scopeItemDetail($model){
return $model->with('item.m_item_d');
}

--buat nama fungsi baru, kemudian ambil kolom berdasarkan relasi pada fungsi di model--

// 3. Code transformRow

# contoh 1
public function transformRowData(array $row)
{
$data = [];

if (app()->request->detail_sortir) {
// Mengambil data upload_gambar dari relasi t_pembelian_bahan_mentah_d -> t_penerimaan_bahan_l_sortir
$detailSortir = $this->join('t_penerimaan_bahan_mentah_l_sortir', 't_penerimaan_bahan_mentah_l_sortir.list_sortir_id', '=', 't_pembelian_bahan_mentah_d.list_sortir_id')
->select('t_penerimaan_bahan_mentah_l_sortir.upload_gambar')
->get();

$data = [
'detail_sortir' => $detailSortir
];
}

return array_merge($row, $data);
}

# contoh 2
public function transformRowData(array $row)
{
// Jika ada flag detail_penerimaan di request
if (app()->request->detail_penerimaan) {

// Ambil data detail penerimaan dan retur pembelian barang berdasarkan ID t_retur_penerimaan_barang
$returDetail = \DB::table('t_retur_penerimaan_barang')
->leftJoin('t_penerimaan_barang_d as tpbd', 't_retur_penerimaan_barang.t_penerimaan_barang_id', '=', 'tpbd.t_penerimaan_barang_id')
->leftJoin('t_retur_penerimaan_barang_d as trpbd', 't_retur_penerimaan_barang.id', '=', 'trpbd.t_retur_penerimaan_barang_id')
->where('t_retur_penerimaan_barang.id', $row['id'] ?? null)
->select(
't_retur_penerimaan_barang.no_retur',
'tpbd.kode_item',
'tpbd.nama_item',
'tpbd.qty_diterima',
'trpbd.qty_retur'
)
->get();

// Gabungkan hasil join ke row
$row['detail_retur_barang'] = $returDetail;
}

return $row;
}

// 4. Code onRetrieved
--digunakan untuk mengambil link gambar yang disimpan--

public function onRetrieved($model)
{
$model['list_sortir.upload_gambar'] = url('').'/uploads/t_penerimaan_bahan_l_sortir/'.$model['list_sortir.upload_gambar'];
}

// public function getUploadGambar()
// {
// $data = \DB::table('t_pembelian_bahan_mentah_d')
// ->join('t_pembelian_bahan_mentah', 't_pembelian_bahan_mentah.id', '=', 't_pembelian_bahan_mentah_d.t_pb_mentah_id')
// ->join('t_penerimaan_bahan_mentah', 't_penerimaan_bahan_mentah.id', '=', 't_pembelian_bahan_mentah.no_penerimaan_id')
// ->join('t_penerimaan_bahan_mentah_l_sortir', 't_penerimaan_bahan_mentah_l_sortir.list_sortir_id', '=', 't_pembelian_bahan_mentah_d.list_sortir_id')
// ->select(
// 't_pembelian_bahan_mentah_d.*',
// 't_pembelian_bahan_mentah.no_pb',
// 't_penerimaan_bahan_mentah.no_penerimaan',
// 't_penerimaan_bahan_mentah_l_sortir.upload_gambar'
// )
// ->get();

// return response()->json($data);
// }

// 5. Code updateBefore
--digunakan untuk pengkondisian sebelum update data--
public function updateBefore( $model, $arrayData, $metaData, $id=null )
{
unset($model['list_sortir.upload_gambar']);

// $newArrayData = array_merge( $arrayData, $updateData );
return [
"model" => $model,
"data" => $arrayData,
// "errors" => ['error1']
];
}

// 6. Code createAfter
--digunakan untuk pengkondisian setelah update data--

public function createAfterTransaction( $newdata, $olddata, $data, $meta )
{
// trigger_error(json_encode($newdata)); --pancingan eror--
m_sortir_hist::create([
'm_sortir_id' => $newdata['id'],
'm_item_id' => $newdata['m_item_id'],
'name' => $newdata['name'],
'price' => $newdata['price'],
'desc' => $newdata['desc'] ?? null,
'is_active' => $newdata['is_active'],
'created_at' => $newdata['created_at'],
]);
}

// 7. Code SQL reset sequence
--digunakan saat terjadi duplikat id saat menambah data baru--
SELECT setval('m_gen_id_seq', COALESCE((SELECT MAX(id) FROM m_gen), 1), false);
--ambil nama tabel dan berdasarkan id_seq--

// 8. Code custom save
--digunakan untuk menyimpan data secara berulang--

public function custom_save($request)
{
$data = $request->all();

// Validasi input
$validator = \Validator::make($data, [
'no' => 'nullable|string|max:255',
'date' => 'nullable|date',
'est_date' => 'required|date',
'status' => 'required|string|max:50',

'items' => 'required|array|min:1',
'items.*.m_supp_id' => 'required|integer',
'items.*.m_supp_d_kend_id' => 'required|integer',
'items.*.nopol' => 'required|string|max:50',
'items.*.supir' => 'required|string|max:100',
'items.*.m_item_id' => 'required|integer',
'items.*.est_tonase_kg' => 'required|numeric',
'items.*.sak' => 'required|numeric',
'items.*.desc' => 'nullable|string',
]);

if ($validator->fails()) {
return response()->json(['errors' => $validator->errors()], 422);
}

try {
$savedList = [];

foreach ($data['items'] as $item) {
$subpeng = t_subpeng::create([
'no' => $data['no'] ?? null,
'date' => $data['date'] ?? null,
'est_date' => $data['est_date'],
'status' => $data['status'],

'm_supp_id' => $item['m_supp_id'],
'm_supp_d_kend_id' => $item['m_supp_d_kend_id'],
'nopol' => $item['nopol'],
'supir' => $item['supir'],
'm_item_id' => $item['m_item_id'],
'est_tonase_kg' => $item['est_tonase_kg'],
'sak' => $item['sak'],
'desc' => $item['desc'] ?? null,

// mengambil data user auth
'creator_id' => auth()->id(),
'editor_id' => auth()->id(),
]);

$savedList[] = $subpeng;
}

return response()->json([
'message' => 'Data submit pengiriman berhasil disimpan',
'data' => $savedList
], 201);
} catch (\Exception $e) {
return response()->json([
'error' => 'Terjadi kesalahan saat menyimpan data',
'message' => $e->getMessage()
], 500);
}
}

// 9. Code tambah relasi
public function tarif()
{
return $this->belongsTo(Tarif::class, 'm_tarif_id');
}

public function t_po_lpb() :\HasMany
{
return $this->hasMany('App\Models\BasicModels\t_lpb', 't_purchase_order_id', 'id');
}

// 10. perubahan status transaksi, project TOFAN(KILANG JAGUNG)
public function custom_proses()
{
\DB::beginTransaction();
try {
// Simpan t_penerimaan
$t_penerimaan = new \App\Models\BasicModels\t_penerimaan();
$data = request()->except(['t_penerimaan_d_samp', 't_penerimaan_d_berat']);

// Hitung berat_sudah_diterima dan berat_os
$beratPermintaan = isset($data['berat_permintaan']) ? (float)$data['berat_permintaan'] : 0;
$totalNetto = isset($data['total_netto']) ? (float)$data['total_netto'] : 0;
$beratOutstanding = $beratPermintaan - $totalNetto;

$data['berat_sudah_diterima'] = $totalNetto;
$data['berat_os'] = $beratOutstanding;

$t_penerimaan->fill($data);
$t_penerimaan->save();

// Simpan relasi t_penerimaan_d_samp
if (request()->has('t_penerimaan_d_samp')) {
foreach (request()->t_penerimaan_d_samp as $samp) {
$sampModel = new \App\Models\BasicModels\t_penerimaan_d_samp();
$sampModel->t_penerimaan_id = $t_penerimaan->id;
$sampModel->fill($samp);
$sampModel->save();
}
}

// Simpan relasi t_penerimaan_d_berat
if (request()->has('t_penerimaan_d_berat')) {
foreach (request()->t_penerimaan_d_berat as $berat) {
$beratModel = new \App\Models\BasicModels\t_penerimaan_d_berat();
$beratModel->t_penerimaan_id = $t_penerimaan->id;
$beratModel->fill($berat);
$beratModel->save();
}
}

// Update status_transaksi pada t_po_bahan
$poBahanId = $t_penerimaan->t_po_bahan_id;
$t_po_bahan = \App\Models\BasicModels\t_po_bahan::find($poBahanId);
if ($t_po_bahan) {
$jumlahPenerimaan = \App\Models\BasicModels\t_penerimaan::where('t_po_bahan_id', $poBahanId)->count();
$t_po_bahan->status_transaksi = $jumlahPenerimaan > 0 ? 'PROCESS' : 'PENDING';
$t_po_bahan->save();
}

\DB::commit();
return response()->json([
'status' => 'success',
'message' => 'Data berhasil disimpan dan dihitung',
'data' => [
'berat_sudah_diterima' => $totalNetto,
'berat_outstanding' => $beratOutstanding
]
]);
} catch (\Exception $e) {
\DB::rollback();
return response()->json(['status' => 'error', 'message' => $e->getMessage()], 500);
}
}

public function custom_delete()
{
\DB::beginTransaction();
try {
$id = request()->input('id'); // Ambil ID dari request
$t_penerimaan = \App\Models\BasicModels\t_penerimaan::findOrFail($id);
$poBahanId = $t_penerimaan->t_po_bahan_id;

$t_penerimaan->delete();

// Hitung ulang setelah delete
$remaining = \App\Models\BasicModels\t_penerimaan::where('t_po_bahan_id', $poBahanId)->count();

$t_po_bahan = \App\Models\BasicModels\t_po_bahan::find($poBahanId);
if ($t_po_bahan) {
$t_po_bahan->status_transaksi = $remaining > 0 ? 'PROCESS' : 'PENDING';
$t_po_bahan->save();
}

\DB::commit();
return response()->json(['status' => 'success', 'message' => 'Data berhasil dihapus dan status transaksi diperbarui']);
} catch (\Exception $e) {
\DB::rollback();
return response()->json(['status' => 'error', 'message' => $e->getMessage()], 500);
}
}

// 11. scope menggunakan query
public function scopeGetPBDetail($query)
{
return $query->select('t_penerimaan.id as penerimaan_id','t_penerimaan.*', 'po.*', 'supplier.*', 'item.*')
->join('t_po_bahan as po', 't_penerimaan.t_po_bahan_id', '=', 'po.id')
->join('m_supplier as supplier', 'po.m_supplier_id', '=', 'supplier.id')
->join('m_item as item', 'po.m_item_id', '=', 'item.id')
->leftJoin('t_pi_bahan_d as pi_d', 't_penerimaan.id', '=', 'pi_d.t_penerimaan_id')
->where('t_penerimaan.status', 'POST')
->whereNotIn('t_penerimaan.id', function ($subquery) {
$subquery->select('t_pi_bahan_d.t_penerimaan_id')
->from('t_pi_bahan_d')
->join('t_pi_bahan', 't_pi_bahan.id', '=', 't_pi_bahan_d.t_pi_bahan_id')
->where('t_pi_bahan.status', 'POST');
})
->orderByDesc('t_penerimaan.id');
}

// 12. hapus tabel header-detail dan reset id di query DB
TRUNCATE t_faktur_pembelian_bahan, t_faktur_pembelian_bahan_d RESTART IDENTITY CASCADE;

// 13. debug (dd)
triggertrigger_error(json_encode($newdata));

// 14. format parse float pada landing
{
headerName: 'Konversi (Kg)',
field: 'm_item.konversi',
filter: true,
sortable: true,
flex: 1,
filter: 'ColFilter',
resizable: true,
wrapText: true,
cellClass: ['border-r', '!border-gray-200', 'justify-start'],
valueFormatter: (params) => {
const value = parseFloat(params.value);
return isNaN(value) ? '' : value.toFixed(0); // untuk mengatur 0 setelah koma
}
},

15. custom update saldo accounting
protected static function updateSaldoKas()
    {
        // Hitung total POST penerimaan
        $totalPenerimaan = \DB::table('t_penerimaan_kas')
                            ->where('status', 'POST')
                            ->sum('jumlah');

        // Hitung total POST pengeluaran
        $totalPengeluaran = \DB::table('t_pengeluaran_kas')
                            ->where('status', 'POST')
                            ->sum('jumlah');

        // Saldo akhir
        $saldoAkhir = $totalPenerimaan - $totalPengeluaran;

        // Simpan ke tabel t_saldo
        // Diasumsikan hanya 1 baris di tabel t_saldo, misal ID=1
        \DB::table('t_saldo')->updateOrInsert(
            ['id' => 1],
            ['saldo' => $saldoAkhir]
        );

        return $saldoAkhir;
    }

    public function custom_post()
    {
        $id = request('id');

        // 1. Update status jadi POST
        $updated = $this->where('id', $id)->update(['status' => 'POST']);

        // 2. Update saldo keseluruhan
        $saldo = self::updateSaldoKas();

        // 3. Return
        return [
            'success' => (bool) $updated,
            'saldo'   => (float) $saldo,
        ];
    }
