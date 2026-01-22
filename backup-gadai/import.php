// import customer
import { v4 as uuidv4 } from "uuid";
import { m_customers } from "../models/index.js";
import { sequelize } from "../config/database.js";
import { Op } from "sequelize";

export const t_migration_dataController = {
async importCustomers(req, res) {
const transaction = await sequelize.transaction();
try {
if (!req.excelData || req.excelData.length === 0) {
return res.status(400).json({ message: "File excel wajib diupload" });
}

const rows = req.excelData;

// 1) HEADER MAP
const headerRow = rows[0];
const headerMap = {};
headerRow.forEach((h, idx) => {
if (!h) return;
headerMap[h.toString().trim()] = idx;
});

const nikCol = headerMap["nik"] ?? headerMap["NIK"];
const nameCol = headerMap["name"] ?? headerMap["Nama"] ?? headerMap["nama"];
const phoneCol = headerMap["phone_number"] ?? headerMap["No HP"] ?? headerMap["no_hp"];

if (nikCol === undefined) {
await transaction.rollback();
return res.status(400).json({ message: "Kolom 'nik' tidak ditemukan di Excel" });
}

// 2) PRELOAD NIK DB
const existingNiks = new Set(
(await m_customers.findAll({
attributes: ["nik"],
where: { nik: { [Op.ne]: null } },
raw: true,
transaction,
}))
.map((x) => (x.nik ?? "").toString().trim())
.filter(Boolean)
);

let inserted = 0;
let skipped = 0;

// 3) LOOP
for (let i = 1; i < rows.length; i++) {
    const row=rows[i];

    const nik=(row[nikCol] ?? "" ).toString().trim();
    if (!nik) { skipped++; continue; }

    if (existingNiks.has(nik)) { skipped++; continue; }

    const name=nameCol !==undefined ? (row[nameCol] ?? "" ).toString().trim() : "" ;
    const phone=phoneCol !==undefined ? (row[phoneCol] ?? "" ).toString().trim() : "" ;

    await m_customers.create(
    {
    id: uuidv4(),
    nik,
    name: name || null,
    phone_number: phone || null,
    status: "ACTIVE" ,
    createdAt: new Date(),
    updatedAt: new Date(),
    },
    { transaction }
    );

    existingNiks.add(nik);
    inserted++;
    }

    await transaction.commit();
    return res.json({
    message: "Import customers berhasil" ,
    inserted,
    skipped,
    total_rows: rows.length - 1,
    });
    } catch (err) {
    await transaction.rollback();
    return res.status(500).json({ message: err.message });
    }
    },
    };


// import transaction
import { v4 as uuidv4 } from "uuid" ;
import { sequelize } from "../config/database.js" ;
import { Op } from "sequelize" ;

import {
m_customers,
m_outlets,
m_segment,
m_categories,
m_types,
m_transactions,
m_log_transactions,
m_data_pinjaman,
m_perpanjangan,
d_perpanjangan,
} from "../models/index.js" ;

export const t_migration_dataController={
async importTransactions(req, res) {
const transaction=await sequelize.transaction();

try {
const rows=req.excelData;
if (!rows || rows.length < 2) {
throw new Error("Data excel kosong");
}

//=================HEADER=================const header={};
rows[0].forEach((h, i)=> {
if (h) header[String(h).toLowerCase().trim()] = i;
});
const col = (n) => header[n.toLowerCase()];

// ================= HELPERS =================
const norm = (v) => String(v ?? "").replace(/\s+/g, " ").trim();
const num = (v) =>
Number(String(v ?? "0").replace(/[^\d]/g, "")) || 0;
const toDate = (v) =>
v instanceof Date ? v : v ? new Date(v) : new Date();
const fmtDT = (d) =>
d.toISOString().replace("T", " ").slice(0, 19);
const fmtDate = (d) => d.toISOString().slice(0, 10);

const CONST_COMPANY = "efeec6eb-22d9-4fe7-9096-f8dd18b764d9";
const CONST_PRODUCT = "e17473ad-e6ad-41f7-bd48-9f8f98292d87";

let inserted = 0;

// ================= LOOP =================
for (let i = 1; i < rows.length; i++) {
    const r=rows[i];

    const sbg=norm(r[col("sbg")]);
    if (!sbg) continue;

    //=====SKIP JIKA SBG SUDAH ADA=====const exist=await m_transactions.findOne({
    where: { no_SBG: sbg },
    transaction,
    });
    if (exist) continue;

    const createdAt=toDate(r[col("tanggal")]);
    const jtDate=toDate(r[col("tanggal jt")]);

    // Ambil hari dari createdAt untuk kolom Rak
    const rakValue=createdAt.getDate();

    //=====MASTER LOOKUP=====const outlet=await m_outlets.findOne({
    where: { name: { [Op.iLike]: `%${norm(r[col("outlet")])}%` } },
    transaction,
    });

    const segment=await m_segment.findOne({
    where: { name: { [Op.iLike]: `%${norm(r[col("segmen")])}%` } },
    transaction,
    });

    const category=await m_categories.findOne({
    where: { name: { [Op.iLike]: `%${norm(r[col("kategori")])}%` } },
    transaction,
    });

    const type=await m_types.findOne({
    where: { name: { [Op.iLike]: `%${norm(r[col("tipe")])}%` } },
    transaction,
    });

    const f_grades=category?.f_grades ?? null;
    const f_brands=type?.f_brand_code ?? null;

    //=====CUSTOMER=====const phoneRaw=norm(r[col("no hp")]);
    const phoneTrim=phoneRaw.length> 1 ? phoneRaw.substring(1) : phoneRaw;

    const customer = await m_customers.findOne({
    where: {
    name: { [Op.iLike]: `%${norm(r[col("nasabah")])}%` },
    phone_number: { [Op.like]: `%${phoneTrim}%` },
    },
    transaction,
    });

    // ===== DATA PINJAMAN =====
    const nilaiPinjaman = num(r[col("pokok pinjaman")]);
    const dataPinjaman = await m_data_pinjaman.create(
    {
    id: uuidv4(),
    nilai_pinjaman: nilaiPinjaman,
    nilai_pencairan: nilaiPinjaman,
    nilai_barang: num(r[col("nilai jaminan")]),
    pinjaman_max: 0,
    pinjaman_min: 0,
    biaya_jasa: 0,
    biaya_admin: 0,
    pengurang: 0,
    createdAt,
    updatedAt: createdAt,
    },
    { transaction }
    );

    // ================= LOGIKA PERPANJANGAN (UPDATED) =================
    const perpanjanganKe = Number(r[col("perpanjangan")]) || 0;
    let perpanjanganId = null;

    // Ambil nilai untuk kalkulasi total baru
    const valPokokPinjaman = num(r[col("pokok pinjaman")]);
    const valPokokTerbayar = num(r[col("pokok terbayar")]);
    const totalBaru = valPokokPinjaman - valPokokTerbayar;

    const bunga = num(r[col("jasa terbayar")]);
    const denda = num(r[col("denda terbayar")]);
    const admin = num(r[col("admin terbayar")]);
    const pokok = num(r[col("outstanding pokok")]);

    // Cukup buat m_perpanjangan saja (tidak perlu d_perpanjangan)
    if (perpanjanganKe >= 1) {
    perpanjanganId = uuidv4();
    await m_perpanjangan.create(
    {
    id: perpanjanganId,
    jenis: "perpanjangan",
    bunga: bunga,
    denda: denda,
    denda_sbg: 0,
    total: totalBaru, // Diisi dari (Pokok Pinjaman - Pokok Terbayar)
    upload_struk_photo: null,
    date_perpanjangan: fmtDate(jtDate),
    angsuran: valPokokTerbayar, // Isi dari nilai Pokok Terbayar
    admin: admin,
    pokok: pokok,
    metode_pembayaran: "Tunai",
    createdAt: createdAt,
    updatedAt: createdAt,
    f_bank_id: null,
    nama_pemilik_rekening: null,
    no_rekening: null,
    status_cancel: null,
    tgl_cancel: null,
    },
    { transaction }
    );
    }

    // ===== LOG TRANSAKSI =====
    const logTrx = await m_log_transactions.create(
    {
    id: uuidv4(),
    jenis: "pencairan",
    total: num(r[col("nilai jaminan")]),
    f_perpanjangan: perpanjanganId,
    createdAt,
    updatedAt: createdAt,
    },
    { transaction }
    );

    // ===== TRANSAKSI UTAMA =====
    await m_transactions.create(
    {
    id: uuidv4(),
    f_company: CONST_COMPANY,
    f_product_cat_code: CONST_PRODUCT,
    f_outlet_code: outlet?.id ?? null,
    f_area_code: outlet?.f_area_code ?? null,
    f_segments: segment?.id ?? null,
    f_categories: category?.id ?? null,
    f_types: type?.id ?? null,
    f_grades,
    f_brands,
    f_customers: customer?.id ?? null,
    no_SBG: sbg,
    IMEI: norm(r[col("imei / no seri")]),
    jatuh_tempo: fmtDate(jtDate),
    time_pencairan: fmtDT(createdAt),
    status: "1",
    status_approval: "2",
    status_pencairan: "1",
    jenis_pembayaran: "tunai",
    rak: rakValue,
    f_data_pinjaman: dataPinjaman.id,
    f_log_transactions: logTrx.id,
    createdAt,
    updatedAt: createdAt,
    },
    { transaction }
    );

    inserted++;
    }

    await transaction.commit();
    return res.json({ success: true, inserted });
    } catch (err) {
    if (transaction) await transaction.rollback();
    return res.status(500).json({
    success: false,
    message: err.message,
    });
    }
    },
    };