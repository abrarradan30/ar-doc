// UPDATES STATUS TRANSAKSI 1,2,3,7
async updateStatusTransaksi(req, res) {
    const { m_transactions, m_jatuh_tempo } = req.models;
    const sequelize = m_transactions.sequelize;
    const t = await sequelize.transaction();

    try {
        const now = moment(); // Waktu saat ini sebagai titik acuan untuk perbandingan

        // 1. Ambil semua transaksi yang berpotensi untuk diupdate.
        //    Asumsi status '3' (Telat Bayar) adalah status final dalam alur ini.
        const transactionsToProcess = await m_transactions.findAll({
            where: {
                status: { [Op.notIn]: ['3'] } // Ambil semua yang belum "Telat Bayar"
            },
            order: [['createdAt', 'ASC']], // Urutkan dari yang paling lama
            transaction: t
        });

        // Inisialisasi objek untuk menampung hasil pembaruan
        let responsePayload = {
            telat_bayar: 0,
            reminder_telat_bayar: 0,
            jatuh_tempo: 0,
            reminder_jatuh_tempo: 0,
            created_at_telat_bayar: [],
            created_at_jatuh_tempo: [],
            created_at_reminder_telat_bayar: [],
            created_at_reminder_jatuh_tempo: []
        };

        // Jika tidak ada transaksi untuk diproses, langsung selesaikan.
        if (transactionsToProcess.length === 0) {
            await t.commit(); // Commit transaksi kosong agar tidak menggantung
            return res.status(200).json({
                message: 'Tidak ada transaksi yang memerlukan pembaruan status saat ini.',
                updated_counts: responsePayload
            });
        }

        // 2. Iterasi setiap transaksi dan terapkan logika berdasarkan 'createdAt'-nya sendiri
        for (const trx of transactionsToProcess) {
            const createdAt = moment(trx.createdAt);

            // Definisikan tanggal-tanggal penting berdasarkan createdAt transaksi ini
            const jatuhTempoDate = createdAt.clone().add(1, 'month');
            const reminderJatuhTempoStartDate = jatuhTempoDate.clone().subtract(3, 'days');
            const telatBayarDate = jatuhTempoDate.clone().add(15, 'days');
            const reminderTelatBayarDate = jatuhTempoDate.clone().add(13, 'days');

            // 3. Gunakan if-else-if untuk menerapkan status yang paling sesuai (dari paling parah)
            // ALUR 1: UPDATE STATUS MENJADI '3' (Telat Bayar)
            // Jika hari ini sudah melewati tanggal telat bayar (createdAt + 1 bulan + 15 hari)
            if (now.isAfter(telatBayarDate) && trx.status !== '3') {
                await trx.update({ status: '3' }, { transaction: t });

                // Opsi: Membuat record di m_jatuh_tempo saat status menjadi telat bayar
                await m_jatuh_tempo.create({
                    f_transactions: trx.id,
                    status: 'active', // atau 'telat'
                    date: trx.jatuh_tempo // pastikan field ini ada dan benar
                }, { transaction: t });

                responsePayload.telat_bayar++;
                responsePayload.created_at_telat_bayar.push(trx.createdAt);

                // ALUR 2: UPDATE STATUS MENJADI '4' (Reminder Telat Bayar)
                // Jika hari ini sudah melewati tanggal reminder telat bayar (createdAt + 1 bulan + 13 hari)
            } else if (now.isAfter(reminderTelatBayarDate) && trx.status === '2') {
                await trx.update({ status: '4' }, { transaction: t });
                responsePayload.reminder_telat_bayar++;
                responsePayload.created_at_reminder_telat_bayar.push(trx.createdAt);

                // ALUR 3: UPDATE STATUS MENJADI '2' (Jatuh Tempo)
                // Jika hari ini sudah melewati tanggal jatuh tempo (createdAt + 1 bulan)
            } else if (now.isAfter(jatuhTempoDate) && (trx.status === '0' || trx.status === '1')) {
                await trx.update({ status: '2' }, { transaction: t });
                responsePayload.jatuh_tempo++;
                responsePayload.created_at_jatuh_tempo.push(trx.createdAt);

                // ALUR 4: UPDATE STATUS MENJADI '1' (Reminder Jatuh Tempo)
                // Jika hari ini berada dalam periode reminder (H-3 sebelum jatuh tempo)
            } else if (now.isAfter(reminderJatuhTempoStartDate) && trx.status === '0') {
                await trx.update({ status: '1' }, { transaction: t });
                responsePayload.reminder_jatuh_tempo++;
                responsePayload.created_at_reminder_jatuh_tempo.push(trx.createdAt);
            }
        }

        // Jika semua proses berhasil, commit transaksi
        await t.commit();

        res.status(200).json({
            message: 'Proses pembaruan status transaksi selesai.',
            updated_counts: responsePayload
        });

    } catch (error) {
        // Jika terjadi error, batalkan semua perubahan dalam transaksi
        if (t && !t.finished) {
            await t.rollback();
        }

        console.error("Error running updateStatusTransaksi:", error);
        res.status(500).json({
            message: 'Terjadi kesalahan pada server saat memperbarui status.',
            error: error.message
        });
    }
},

// UPDATES STATUS TRANSAKSI 0,1,2,3,4,5,6,7
async updateStatusTransaksi(req, res) {
    // 1. Memuat semua model yang dibutuhkan, termasuk relasi baru.
    const { m_transactions, m_jatuh_tempo, m_log_transactions, m_lelang, m_jaminan } = req.models;
    const sequelize = m_transactions.sequelize;
    const t = await sequelize.transaction();

    try {
        const now = moment(); // Waktu saat ini sebagai titik acuan.

        // ALUR 0: PENGAMBILAN DATA TRANSAKSI
        // Mengambil semua transaksi yang statusnya belum final (bukan 'Tutup' atau 'Terjual').
        // Status 'Lelang' (5) masih bisa berubah menjadi 'Terjual' (6), jadi tetap diproses.
        const transactionsToProcess = await m_transactions.findAll({
            where: {
                status: { [Op.notIn]: ['4', '6'] } // '4'=Tutup, '6'=Terjual
            },
            order: [['createdAt', 'ASC']],
            transaction: t
        });

        // Inisialisasi objek respons untuk menampung hasil pembaruan.
        let responsePayload = {
            tutup: 0,
            terjual: 0,
            lelang: 0,
            telat_bayar: 0,
            reminder_telat_bayar: 0, // Hanya untuk notifikasi, tidak mengubah status DB
            jatuh_tempo: 0,
            reminder_jatuh_tempo: 0,
            created_at_tutup: [],
            created_at_terjual: [],
            created_at_lelang: [],
            created_at_telat_bayar: [],
            created_at_jatuh_tempo: [],
            created_at_reminder_telat_bayar: [],
            created_at_reminder_jatuh_tempo: []
        };

        if (transactionsToProcess.length === 0) {
            await t.commit();
            return res.status(200).json({
                message: 'Tidak ada transaksi yang memerlukan pembaruan status saat ini.',
                updated_counts: responsePayload
            });
        }

        // 2. Iterasi setiap transaksi untuk menerapkan logika status.
        for (const trx of transactionsToProcess) {

            // =================================================================================
            // ALUR PRIORITAS: Pengecekan berdasarkan event (Pelunasan, Lelang, Terjual)
            // Urutan pengecekan dari yang paling final.
            // =================================================================================

            // ALUR BARU 1: UPDATE STATUS MENJADI '4' (Tutup / Lunas)
            // Jika ada log transaksi terkait yang field f_pelunasan-nya tidak null.
            if (trx.f_log_transactions) {
                const log = await m_log_transactions.findByPk(trx.f_log_transactions, { transaction: t });
                if (log && log.f_pelunasan != null && trx.status !== '4') {
                    await trx.update({ status: '4' }, { transaction: t });
                    responsePayload.tutup++;
                    responsePayload.created_at_tutup.push(trx.createdAt);
                    continue; // Lanjut ke transaksi berikutnya karena status sudah final.
                }
            }

            // ALUR BARU 2: UPDATE STATUS MENJADI '6' (Terjual)
            // Jika jaminan terkait statusnya sudah 'terjual'.
            if (trx.f_jaminan) {
                const jaminan = await m_jaminan.findByPk(trx.f_jaminan, { transaction: t });
                if (jaminan && jaminan.status === 'terjual' && trx.status !== '6') {
                    await trx.update({ status: '6' }, { transaction: t });
                    responsePayload.terjual++;
                    responsePayload.created_at_terjual.push(trx.createdAt);
                    continue; // Lanjut ke transaksi berikutnya karena status sudah final.
                }
            }

            // ALUR BARU 3: UPDATE STATUS MENJADI '5' (Lelang)
            // Jika transaksi sudah masuk ke dalam tabel lelang.
            const lelangExists = await m_lelang.findOne({
                where: { f_transactions: trx.id },
                transaction: t
            });
            if (lelangExists && trx.status !== '5') {
                await trx.update({ status: '5' }, { transaction: t });
                responsePayload.lelang++;
                responsePayload.created_at_lelang.push(trx.createdAt);
                continue; // Lanjut ke transaksi berikutnya untuk mencegah dioverwrite oleh status waktu.
            }

            // =================================================================================
            // ALUR BERBASIS WAKTU: Pengecekan berdasarkan tanggal (Jatuh Tempo, Telat, Reminder)
            // Hanya dijalankan jika transaksi tidak memenuhi kriteria alur prioritas di atas.
            // =================================================================================

            const createdAt = moment(trx.createdAt);

            // Definisikan tanggal-tanggal penting berdasarkan createdAt transaksi.
            const jatuhTempoDate = createdAt.clone().add(1, 'month');
            const reminderJatuhTempoStartDate = jatuhTempoDate.clone().subtract(3, 'days');
            const telatBayarDate = jatuhTempoDate.clone().add(15, 'days');
            const reminderTelatBayarDate = jatuhTempoDate.clone().add(13, 'days');

            // Gunakan if-else-if untuk menerapkan status yang paling sesuai (dari yang paling kritis).
            // ALUR 4: UPDATE STATUS MENJADI '3' (Telat Bayar)
            // Jika hari ini sudah melewati tanggal telat bayar (createdAt + 1 bulan + 15 hari).
            if (now.isAfter(telatBayarDate) && trx.status !== '3') {
                await trx.update({ status: '3' }, { transaction: t });

                // Opsi: Membuat record di m_jatuh_tempo saat status menjadi telat bayar.
                await m_jatuh_tempo.create({
                    f_transactions: trx.id,
                    status: 'telat',
                    date: trx.jatuh_tempo
                }, { transaction: t });

                responsePayload.telat_bayar++;
                responsePayload.created_at_telat_bayar.push(trx.createdAt);

                // ALUR 5: PROSES "REMINDER TELAT BAYAR" (TANPA UPDATE STATUS)
                // Jika hari ini sudah melewati tanggal reminder telat bayar (createdAt + 1 bulan + 13 hari).
                // Solusi baru: Tidak mengubah status menjadi '4', hanya mencatatnya di respons.
            } else if (now.isAfter(reminderTelatBayarDate) && trx.status === '2') { // Status sebelumnya harus 'Jatuh Tempo'
                responsePayload.reminder_telat_bayar++;
                responsePayload.created_at_reminder_telat_bayar.push(trx.createdAt);

                // ALUR 6: UPDATE STATUS MENJADI '2' (Jatuh Tempo)
                // Jika hari ini sudah melewati tanggal jatuh tempo (createdAt + 1 bulan).
            } else if (now.isAfter(jatuhTempoDate) && ['0', '1'].includes(trx.status)) { // Status sebelumnya 'Aktif' atau 'Reminder'
                await trx.update({ status: '2' }, { transaction: t });
                responsePayload.jatuh_tempo++;
                responsePayload.created_at_jatuh_tempo.push(trx.createdAt);

                // ALUR 7: UPDATE STATUS MENJADI '1' (Reminder Jatuh Tempo)
                // Jika hari ini berada dalam periode reminder (H-3 sebelum jatuh tempo).
            } else if (now.isAfter(reminderJatuhTempoStartDate) && trx.status === '0') { // Status sebelumnya harus 'Aktif'
                await trx.update({ status: '1' }, { transaction: t });
                responsePayload.reminder_jatuh_tempo++;
                responsePayload.created_at_reminder_jatuh_tempo.push(trx.createdAt);
            }
        }

        // Jika semua proses berhasil, commit transaksi.
        await t.commit();

        res.status(200).json({
            message: 'Proses pembaruan status transaksi selesai.',
            updated_counts: responsePayload
        });

    } catch (error) {
        // Jika terjadi error, batalkan semua perubahan.
        if (t && !t.finished) {
            await t.rollback();
        }

        console.error("Error running updateStatusTransaksi:", error);
        res.status(500).json({
            message: 'Terjadi kesalahan pada server saat memperbarui status.',
            error: error.message
        });
    }
},

// UPDATES STATUS TRANSAKSI perhitungan perpanjangan berdasarkan date_perpanjangan
async updateStatusTransaksi(req, res) {
        // 1. Memuat semua model yang dibutuhkan.
        const { m_transactions, m_jatuh_tempo, m_log_transactions, m_lelang, m_jaminan, m_perpanjangan, d_perpanjangan } = req.models;
        const sequelize = m_transactions.sequelize;
        const t = await sequelize.transaction();

        try {
            const now = moment(); // Waktu saat ini sebagai titik acuan.

            // ALUR 0: PENGAMBILAN DATA TRANSAKSI
            const transactionsToProcess = await m_transactions.findAll({
                where: {
                    status: { [Op.notIn]: ['4', '6'] } // '4'=Tutup, '6'=Terjual
                },
                order: [['createdAt', 'ASC']],
                transaction: t
            });

            let responsePayload = {
                tutup: 0,
                terjual: 0,
                lelang: 0,
                telat_bayar: 0,
                jatuh_tempo: 0,
                reminder_jatuh_tempo: 0,
                diperpanjang_menjadi_aktif: 0,
                reminder_telat_bayar: 0,
                created_at_tutup: [],
                created_at_terjual: [],
                created_at_lelang: [],
                created_at_telat_bayar: [],
                created_at_jatuh_tempo: [],
                created_at_reminder_jatuh_tempo: [],
                created_at_diperpanjang_menjadi_aktif: [],
                created_at_reminder_telat_bayar: []
            };

            if (transactionsToProcess.length === 0) {
                await t.commit();
                return res.status(200).json({
                    message: 'Tidak ada transaksi yang memerlukan pembaruan status saat ini.',
                    updated_counts: responsePayload
                });
            }

            // 2. Iterasi setiap transaksi untuk menerapkan logika status.
            for (const trx of transactionsToProcess) {

                // =================================================================================
                // ALUR PRIORITAS: Pengecekan berdasarkan event final (Pelunasan, Lelang, Terjual)
                // =================================================================================

                if (trx.f_log_transactions) {
                    const log = await m_log_transactions.findByPk(trx.f_log_transactions, { transaction: t });
                    if (log && log.f_pelunasan != null && trx.status !== '4') {
                        await trx.update({ status: '4' }, { transaction: t });
                        responsePayload.tutup++;
                        responsePayload.created_at_tutup.push(trx.createdAt);
                        continue;
                    }
                }

                if (trx.f_jaminan) {
                    const jaminan = await m_jaminan.findByPk(trx.f_jaminan, { transaction: t });
                    if (jaminan && jaminan.status === 'terjual' && trx.status !== '6') {
                        await trx.update({ status: '6' }, { transaction: t });
                        responsePayload.terjual++;
                        responsePayload.created_at_terjual.push(trx.createdAt);
                        continue;
                    }
                }

                const lelangExists = await m_lelang.findOne({
                    where: { f_transactions: trx.id },
                    transaction: t
                });
                if (lelangExists && trx.status !== '5') {
                    await trx.update({ status: '5' }, { transaction: t });
                    responsePayload.lelang++;
                    responsePayload.created_at_lelang.push(trx.createdAt);
                    continue;
                }

                // =================================================================================
                // BAGIAN YANG DIPERBAIKI: PENENTUAN TANGGAL JATUH TEMPO EFEKTIF
                // =================================================================================
                let effectiveJatuhTempoDate = null; // DIUBAH: Inisialisasi sebagai null, tidak ada kalkulasi default.
                let isExtended = false;

                if (trx.f_log_transactions) {
                    const log = await m_log_transactions.findByPk(trx.f_log_transactions, { transaction: t });

                    if (log && log.f_perpanjangan) {
                        const latestDetailPerpanjangan = await d_perpanjangan.findOne({
                            where: { f_perpanjangan: log.f_perpanjangan },
                            order: [['date_perpanjangan', 'DESC']],
                            transaction: t
                        });

                        if (latestDetailPerpanjangan && latestDetailPerpanjangan.date_perpanjangan) {
                            effectiveJatuhTempoDate = moment(latestDetailPerpanjangan.date_perpanjangan);
                            isExtended = true;
                        } else {
                            const masterPerpanjangan = await m_perpanjangan.findByPk(log.f_perpanjangan, { transaction: t });
                            if (masterPerpanjangan && masterPerpanjangan.date_perpanjangan) {
                                effectiveJatuhTempoDate = moment(masterPerpanjangan.date_perpanjangan);
                                isExtended = true;
                            }
                        }
                    }
                }

                // DIUBAH: Seluruh logika berbasis waktu hanya berjalan jika `effectiveJatuhTempoDate` ditemukan.
                if (effectiveJatuhTempoDate) {
                    // =================================================================================
                    // LOGIKA BARU: UPDATE STATUS MENJADI '0' (AKTIF) JIKA DIPERPANJANG
                    // =================================================================================
                    if (isExtended && now.isBefore(effectiveJatuhTempoDate) && trx.status !== '0') {
                        await trx.update({ status: '0' }, { transaction: t });
                        responsePayload.diperpanjang_menjadi_aktif++;
                        responsePayload.created_at_diperpanjang_menjadi_aktif.push(trx.createdAt);
                    }

                    // =================================================================================
                    // ALUR BERBASIS WAKTU: Menggunakan `effectiveJatuhTempoDate`
                    // =================================================================================
                    const reminderJatuhTempoStartDate = effectiveJatuhTempoDate.clone().subtract(3, 'days');
                    const telatBayarDate = effectiveJatuhTempoDate.clone().add(15, 'days');
                    const reminderTelatBayarDate = effectiveJatuhTempoDate.clone().add(13, 'days');

                    if (now.isAfter(telatBayarDate) && trx.status !== '3') {
                        await trx.update({ status: '3' }, { transaction: t });
                        await m_jatuh_tempo.create({
                            f_transactions: trx.id,
                            status: 'telat',
                            date: trx.jatuh_tempo
                        }, { transaction: t });
                        responsePayload.telat_bayar++;
                        responsePayload.created_at_telat_bayar.push(trx.createdAt);

                    } else if (now.isAfter(reminderTelatBayarDate) && trx.status === '2') {
                        responsePayload.reminder_telat_bayar++;
                        responsePayload.created_at_reminder_telat_bayar.push(trx.createdAt);

                    } else if (now.isAfter(effectiveJatuhTempoDate) && ['0', '1'].includes(trx.status)) {
                        await trx.update({ status: '2' }, { transaction: t });
                        responsePayload.jatuh_tempo++;
                        responsePayload.created_at_jatuh_tempo.push(trx.createdAt);

                    } else if (now.isAfter(reminderJatuhTempoStartDate) && trx.status === '0') {
                        await trx.update({ status: '1' }, { transaction: t });
                        responsePayload.reminder_jatuh_tempo++;
                        responsePayload.created_at_reminder_jatuh_tempo.push(trx.createdAt);
                    }
                }
            }

            await t.commit();

            res.status(200).json({
                message: 'Proses pembaruan status transaksi selesai.',
                updated_counts: responsePayload
            });

        } catch (error) {
            if (t && !t.finished) {
                await t.rollback();
            }
            console.error("Error running updateStatusTransaksi:", error);
            res.status(500).json({
                message: 'Terjadi kesalahan pada server saat memperbarui status.',
                error: error.message
            });
        }
    },

// LIMIT CREATE TRANSACTIONS 
m_transactions.addHook('beforeCreate', async (instance, options) => {

    // --- VALIDASI BATASAN GADAI ---
    // validasi id pelanggan dan tipe barang 
    if (instance.f_customers && instance.f_types) {
        // status transaksi
        const activeStasuses = ['0', '1', '2', '3', '4', '5', '6', '7'];

        // 1. Cari semua transaksi aktif milik pelanggan ini
        const customerActiveTransactions = await m_transactions.findAll({
        where: {
            f_customers: instance.f_customers,
            status: {
            [Op.in]: activeStasuses
            }
        },
        // perbandingan menggunakan f_types 
        attributes: ['f_types'], 
        raw: true // dapatkan hasil sebagai plain object
        });

        // 2. Cek Batasan Total Maksimal 5 Barang
        const totalCount = customerActiveTransactions.length;
        if (totalCount >= 5) {
        // Jika sudah punya 5 barang aktif atau lebih, tolak.
        throw new Error('Batas maksimal pengajuan adalah 5 barang per nasabah.');
        }

        // 3. Cek Batasan Maksimal 2 Barang dengan Tipe yang Sama
        const sameTypeCount = customerActiveTransactions.filter(
        tx => tx.f_types === instance.f_types
        ).length;

        if (sameTypeCount >= 2) {
        // Jika sudah punya 2 barang dengan tipe yang sama, tolak.
        throw new Error(`Batas maksimal pengajuan untuk tipe barang ini adalah 2 unit.`);
        }
    }

    1. Validasi id pelanggan dan tipe barang -> berdasarkan pelunasan (t/f)
    if (instance.f_customers && instance.f_types) {

        // Akses model 
        const { m_transactions, m_log_transactions } = instance.constructor.sequelize.models;

        const activeStasuses = ['0', '1', '2', '3', '4', '5', '6', '7'];

        // 2. Cari semua transaksi customer dan log terkait
        const allCustomerTransactions = await m_transactions.findAll({
            where: {
                f_customers: instance.f_customers,
            },
            include: [{
                model: m_log_transactions,
                as: 'm_log_transaction', // Pastikan alias ini sesuai dengan definisi relasi Anda
                attributes: ['f_pelunasan'],
                required: false // Gunakan LEFT JOIN agar transaksi tanpa log tetap muncul
            }],
            transaction: options.transaction // Jika proses ini bagian dari transaksi database
        });

        // [PERBAIKAN] 3. Filter mendapatkan transaksi yang aktif
        const trulyActiveTransactions = allCustomerTransactions.filter(tx => {
            // Kondisi 1: Status transaksi harus termasuk dalam daftar status aktif.
            const isStatusActive = activeStasuses.includes(tx.status);

            // Kondisi 2: Transaksi dianggap "belum lunas" jika tidak memiliki relasi ke log atau 
            // memiliki log, tapi f_pelunasan null 
            const isNotPaidOff = !tx.m_log_transaction || tx.m_log_transaction.f_pelunasan === null;

            // Transaksi dihitung sebagai "aktif" jika statusnya aktif DAN memenuhi kondisi "belum lunas".
            return isStatusActive && isNotPaidOff;
        });

        // 4. Hitung total transaksi yang benar-benar aktif
        const totalCount = trulyActiveTransactions.length;

        // 5. Cek Batasan Total Maksimal 5 Barang
        if (totalCount >= 5) {
            throw new Error(`Batas maksimal pengajuan adalah 5 barang aktif per nasabah. Anda saat ini memiliki ${totalCount} barang aktif.`);
        }

        // 6. Hitung dan Cek Batasan Maksimal 2 Barang dengan Tipe yang Sama
        const sameTypeCount = trulyActiveTransactions.filter(
            tx => tx.f_types === instance.f_types
        ).length;

        if (sameTypeCount >= 2) {
            throw new Error(`Batas maksimal pengajuan untuk tipe barang ini adalah 2 unit. Anda sudah memiliki ${sameTypeCount} unit aktif.`);
        }
    }

    // 1. Validasi id pelanggan dan tipe barang -> berdasarkan pelunasan (t/f) & cancel transactions
    if (instance.f_customers && instance.f_types) {

        const { m_transactions, m_log_transactions } = instance.constructor.sequelize.models;

        const activeStasuses = ['0', '1', '2', '3', '4', '5', '6', '7'];

        // Cari semua transaksi customer dan log terkait
        const allCustomerTransactions = await m_transactions.findAll({
            where: {
                f_customers: instance.f_customers,
            },
            include: [{
                model: m_log_transactions,
                as: 'm_log_transaction',
                attributes: ['f_pelunasan'],
                required: false
            }],
            transaction: options.transaction
        });

        // Filter transaksi yang benar-benar aktif
        const trulyActiveTransactions = allCustomerTransactions.filter(tx => {
            // KONDISI BARU: Abaikan transaksi jika status_approval = '3' atau status_pinjaman = 'reject_bm'
            const isRejected = tx.status_approval === '3' || tx.status_pinjaman === 'reject_bm';
            if (isRejected) {
                return false;
            }

            // Kondisi 1: Status transaksi harus termasuk dalam daftar status aktif.
            const isStatusActive = activeStasuses.includes(tx.status);

            // Kondisi 2: Transaksi dianggap "belum lunas" jika tidak memiliki relasi ke log atau
            // memiliki log, tapi f_pelunasan null
            const isNotPaidOff = !tx.m_log_transaction || tx.m_log_transaction.f_pelunasan === null;

            // Transaksi dihitung sebagai "aktif" jika statusnya aktif DAN memenuhi kondisi "belum lunas".
            return isStatusActive && isNotPaidOff;
        });

        // Hitung total transaksi yang benar-benar aktif
        const totalCount = trulyActiveTransactions.length;

        // Cek Batasan Total Maksimal 5 Barang
        if (totalCount >= 5) {
            throw new Error(`Batas maksimal pengajuan adalah 5 barang aktif per nasabah. Anda saat ini memiliki ${totalCount} barang aktif.`);
        }

        // Hitung dan Cek Batasan Maksimal 2 Barang dengan Tipe yang Sama
        const sameTypeCount = trulyActiveTransactions.filter(
            tx => tx.f_types === instance.f_types
        ).length;

        if (sameTypeCount >= 2) {
            throw new Error(`Batas maksimal pengajuan untuk tipe barang ini adalah 2 unit. Anda sudah memiliki ${sameTypeCount} unit aktif.`);
        }
    }


    // Pastikan no_SBG belum diset dari frontend atau jika diset, kita akan menimpanya
    if (!instance.no_SBG) {
        const now = new Date();
        const year = now.getFullYear().toString().slice(-2); // Ambil 2 digit terakhir tahun
        const month = (now.getMonth() + 1).toString().padStart(2, '0'); // Bulan (01-12)
        const day = now.getDate().toString().padStart(2, '0'); // Tanggal (01-31)
        const todayDateCode = `${year}${month}${day}`;

        let outletCode = ' '; // Default jika tidak ada userOutlet di instance

        // Dapatkan kode outlet berdasarkan f_outlet_code (UUID) dari instance transaksi
        // Asumsi f_outlet_code adalah UUID dari tabel m_outlets
        if (instance.f_outlet_code) {
            try {
                const outlet = await m_outlets.findByPk(instance.f_outlet_code);
                if (outlet && outlet.code) {
                    outletCode = outlet.code;
                } else {
                    console.warn(`Outlet with ID ${instance.f_outlet_code} not found or no code defined. Using default 'LOJ'.`);
                }
            } catch (error) {
                console.error("Error fetching outlet code in beforeCreate hook:", error);
                // Lanjutkan dengan default 'LOJ' jika terjadi error
            }
        }

        // Format prefix SBG yang akan dicari
        const sbgPrefix = `SBG/${outletCode}/${todayDateCode}/`;

        // Cari transaksi terakhir untuk hari ini dengan prefix yang sama
        // Gunakan operator LIKE untuk mencari berdasarkan awalan
        const latestTransaction = await m_transactions.findOne({
            where: {
                no_SBG: {
                    [Op.like]: `${sbgPrefix}%` // Cari yang diawali dengan prefix ini
                },
                // Pastikan juga berdasarkan tanggal pembuatan jika ada kolom tanggal terpisah
                // atau jika created_at hanya menyimpan tanggal tanpa waktu untuk akurasi yang lebih tinggi
                // Jika `created_at` adalah timestamp lengkap, `Op.like` pada prefix sudah cukup baik.
                createdAt: {
                    [Op.between]: [
                        new Date(now.getFullYear(), now.getMonth(), now.getDate(), 0, 0, 0),
                        new Date(now.getFullYear(), now.getMonth(), now.getDate(), 23, 59, 59)
                    ]
                }
            },
            order: [
                // Urutkan berdasarkan no_SBG secara descending untuk mendapatkan sequence terbesar
                // Ini berfungsi karena '002' > '001' dalam string jika panjangnya sama
                ['no_SBG', 'DESC']
            ],
            paranoid: false // Sertakan data yang soft-delete jika Anda menggunakannya
        });

        let sequence = 1; // Mulai dari 1 jika belum ada transaksi untuk hari itu

        if (latestTransaction) {
            // Ambil bagian sequence dari no_SBG yang ditemukan
            const parts = latestTransaction.no_SBG.split('/');
            if (parts.length === 4) {
                //const lastSequenceStr = parts[3];
                //const lastSequenceNum = parseInt(lastSequenceStr, 10);
                const lastSequenceNum = parseInt(parts[3], 10);
                if (!isNaN(lastSequenceNum)) {
                    sequence = lastSequenceNum + 1;
                }
            }
        }

        const newSequence = sequence.toString().padStart(3, '0');
        instance.no_SBG = `${sbgPrefix}${newSequence}`;
        console.log('Generated SBG Number in hook:', instance.no_SBG);
    }

    return instance;
});

// status transaksi (m_jatuh_tempo, m_lelang, m_jaminan)
// UPDATES STATUS TRANSAKSI
    async updateStatusTransaksi(req, res) {
        const {
            m_transactions,
            m_jatuh_tempo,
            m_log_transactions,
            m_lelang,
            m_jaminan,
            m_perpanjangan,
            d_perpanjangan
        } = req.models;

        const sequelize = m_transactions.sequelize;
        const Op = sequelize.Sequelize.Op;
        const t = await sequelize.transaction();

        try {
            const now = moment();

            const transactionsToProcess = await m_transactions.findAll({
                where: {
                    status: { [Op.notIn]: ['4'] }  // hanya status '4' (tutup) dianggap final di awal; kita akan tangani 6/8/5 internal
                },
                order: [['createdAt', 'ASC']],
                transaction: t
            });

            let responsePayload = {
                tutup: 0,
                terjual: 0,
                lelang: 0,
                telat_bayar: 0,
                jatuh_tempo: 0,
                reminder_jatuh_tempo: 0,
                diperpanjang_menjadi_aktif: 0,
                reminder_telat_bayar: 0,
                dipusat: 0,
                created_at_tutup: [],
                created_at_terjual: [],
                created_at_lelang: [],
                created_at_telat_bayar: [],
                created_at_jatuh_tempo: [],
                created_at_reminder_jatuh_tempo: [],
                created_at_diperpanjang_menjadi_aktif: [],
                created_at_reminder_telat_bayar: [],
                created_at_dipusat: []
            };

            if (transactionsToProcess.length === 0) {
                await t.commit();
                return res.status(200).json({
                    message: 'Tidak ada transaksi yang perlu diperbarui.',
                    updated_counts: responsePayload
                });
            }

            for (const trx of transactionsToProcess) {
                const oldStatus = trx.status;
                let currentStatus = trx.status;

                // ===================================================================
                // 1. PELUNASAN — jika ada f_pelunasan, ubah ke status '4'
                // ===================================================================
                if (trx.f_log_transactions) {
                    const log = await m_log_transactions.findByPk(trx.f_log_transactions, { transaction: t });
                    if (log && log.f_pelunasan != null && currentStatus !== '4') {
                        // ubah ke 4
                        await trx.update({ status: '4' }, { transaction: t });
                        currentStatus = '4';

                        // Cleanup: hapus m_jatuh_tempo, hapus m_lelang,
                        // *Tapi jangan hapus m_jaminan* agar histori tetap ada
                        await m_jatuh_tempo.destroy({ where: { f_transactions: trx.id }, transaction: t });
                        await m_lelang.destroy({ where: { f_transactions: trx.id }, transaction: t });
                        // jangan hapus m_jaminan

                        responsePayload.tutup++;
                        responsePayload.created_at_tutup.push(trx.createdAt);
                        console.log(`[Status Update] ${trx.id}: ${oldStatus} -> 4 (Tutup/Pelunasan)`);
                        continue;
                    }
                }

                // ===================================================================
                // 2. LE‐JAMINAN: periksa relasi m_lelang / m_jaminan
                // ===================================================================
                const lelang = await m_lelang.findOne({
                    where: { f_transactions: trx.id },
                    transaction: t
                });

                let jaminan = null;
                if (lelang) {
                    jaminan = await m_jaminan.findOne({
                        where: { f_lelang: lelang.id },
                        transaction: t
                    });
                }

                // ---------------------------------------------------
                // 2a. KOREKSI: jika status sekarang adalah '6' tapi **tidak ada jaminan atau lelang**
                // Maka koreksi ke status '3' (Telat Bayar) — atau bisa dipertimbangkan ke logika waktu
                // ---------------------------------------------------
                if ((!lelang || !jaminan) && currentStatus === '6') {
                    console.log(`[Fix] ${trx.id}: status 6 tanpa lelang/jaminan — koreksi ke telat bayar (3)`);

                    // delete jatuh_tempo agar tidak mengganggu
                    await m_jatuh_tempo.destroy({ where: { f_transactions: trx.id }, transaction: t });

                    // langsung ubah ke status 3
                    await trx.update({ status: '3' }, { transaction: t });
                    currentStatus = '3';
                    responsePayload.telat_bayar++;
                    responsePayload.created_at_telat_bayar.push(trx.createdAt);
                    continue;
                }

                // ---------------------------------------------------
                // 2b. Jika ada jaminan, ambil status jaminan
                // ---------------------------------------------------
                if (jaminan) {
                    const jamStatus = (jaminan.status || '').toLowerCase();

                    // TERJUAL
                    if (jamStatus === 'terjual' && currentStatus !== '6') {
                        await trx.update({ status: '6' }, { transaction: t });
                        currentStatus = '6';
                        responsePayload.terjual++;
                        responsePayload.created_at_terjual.push(trx.createdAt);
                        console.log(`[Status Update] ${trx.id}: ${oldStatus} -> 6 (Terjual)`);
                        continue;
                    }

                    // DI PUSAT (dikirim / diterima)
                    if (['dikirim', 'diterima'].includes(jamStatus) && currentStatus !== '8') {
                        await trx.update({ status: '8' }, { transaction: t });
                        currentStatus = '8';
                        responsePayload.dipusat++;
                        responsePayload.created_at_dipusat.push(trx.createdAt);
                        console.log(`[Status Update] ${trx.id}: ${oldStatus} -> 8 (Di Pusat)`);
                        continue;
                    }

                    // DIKEMBALIKAN
                    if (jamStatus === 'dikembalikan' && currentStatus !== '5') {
                        // jika dikembalikan, set transaksi ke lelang kembali ‘5’
                        await trx.update({ status: '5' }, { transaction: t });
                        currentStatus = '5';
                        responsePayload.lelang++;
                        responsePayload.created_at_lelang.push(trx.createdAt);
                        console.log(`[Status Update] ${trx.id}: ${oldStatus} -> 5 (Dikembalikan)`);

                        // Hapus jatuh_tempo agar tidak memicu log lebih lanjut
                        await m_jatuh_tempo.destroy({ where: { f_transactions: trx.id }, transaction: t });

                        continue;
                    }
                }

                // ---------------------------------------------------
                // 2c. Jika hanya ada lelang tapi belum ada jaminan
                // Set status transaksi ke 5
                // ---------------------------------------------------
                if (lelang && !jaminan && !['5', '6', '8'].includes(currentStatus)) {
                    await trx.update({ status: '5' }, { transaction: t });
                    currentStatus = '5';
                    responsePayload.lelang++;
                    responsePayload.created_at_lelang.push(trx.createdAt);
                    console.log(`[Status Update] ${trx.id}: ${oldStatus} -> 5 (Lelang tanpa jaminan)`);
                    // Hapus jatuh_tempo agar tidak konflik
                    await m_jatuh_tempo.destroy({ where: { f_transactions: trx.id }, transaction: t });
                    continue;
                }

                // ---------------------------------------------------
                // 2d. Jika transaksi sudah final (4), atau sudah status lelang (5), terjual (6), pusat (8)
                // Tidak lanjut ke logika waktu
                // ---------------------------------------------------
                if (['4', '6', '8', '5'].includes(currentStatus)) {
                    continue;
                }

                // ===================================================================
                // 3. LOGIKA JATUH TEMPO / PERPANJANGAN / REMINDER
                // ===================================================================

                // 3a. Tentukan jatuh tempo efektif (termasuk perpanjangan)
                let effectiveJatuhTempoDate = null;
                let isExtended = false;

                if (trx.f_log_transactions) {
                    const log = await m_log_transactions.findByPk(trx.f_log_transactions, { transaction: t });
                    if (log && log.f_perpanjangan) {
                        const latestDetail = await d_perpanjangan.findOne({
                            where: { f_perpanjangan: log.f_perpanjangan },
                            order: [['date_perpanjangan', 'DESC']],
                            transaction: t
                        });
                        if (latestDetail?.date_perpanjangan) {
                            effectiveJatuhTempoDate = moment(latestDetail.date_perpanjangan);
                            isExtended = true;
                        } else {
                            const master = await m_perpanjangan.findByPk(log.f_perpanjangan, { transaction: t });
                            if (master?.date_perpanjangan) {
                                effectiveJatuhTempoDate = moment(master.date_perpanjangan);
                                isExtended = true;
                            }
                        }
                    }
                }

                if (!effectiveJatuhTempoDate && trx.jatuh_tempo) {
                    effectiveJatuhTempoDate = moment(trx.jatuh_tempo);
                }

                if (!effectiveJatuhTempoDate) {
                    // Tidak punya tanggal jatuh tempo, skip
                    continue;
                }

                // 3b. Logika perpanjangan: bila perpanjangan aktif dan sekarang < jatuh tempo
                if (isExtended && now.isBefore(effectiveJatuhTempoDate) && currentStatus !== '0') {
                    // ubah ke status aktif (0)
                    await trx.update({ status: '0' }, { transaction: t });
                    currentStatus = '0';

                    // hapus record jatuh_tempo agar tidak memicu log di lain waktu
                    await m_jatuh_tempo.destroy({ where: { f_transactions: trx.id }, transaction: t });

                    responsePayload.diperpanjang_menjadi_aktif++;
                    responsePayload.created_at_diperpanjangan_menjadi_aktif
                        ? responsePayload.created_at_diperpanjangan_menjadi_aktif.push(trx.createdAt)
                        : responsePayload.created_at_diperpanjangan_menjadi_aktif = [trx.createdAt];

                    console.log(`[Status Update] ${trx.id}: ${oldStatus} -> 0 (Aktif karena perpanjangan)`);
                    continue;
                }

                // 3c. Logika based waktu: reminder jatuh tempo, jatuh tempo, telat, reminder telat
                const reminderJT = effectiveJatuhTempoDate.clone().subtract(3, 'days');
                const jatuhTempo = effectiveJatuhTempoDate;
                const reminderTelat = effectiveJatuhTempoDate.clone().add(13, 'days');
                const telat = effectiveJatuhTempoDate.clone().add(15, 'days');

                // Jika sudah telat > 15 hari
                if (now.isAfter(telat) && currentStatus !== '3') {
                    await trx.update({ status: '3' }, { transaction: t });
                    currentStatus = '3';

                    // buat record jatuh_tempo jika belum ada
                    await m_jatuh_tempo.findOrCreate({
                        where: { f_transactions: trx.id },
                        defaults: {
                            f_transactions: trx.id,
                            status: 'active',
                            date: jatuhTempo.format('YYYY-MM-DD HH:mm:ss')
                        },
                        transaction: t
                    });

                    responsePayload.telat_bayar++;
                    responsePayload.created_at_telat_bayar.push(trx.createdAt);
                    console.log(`[Status Update] ${trx.id}: ${oldStatus} -> 3 (Telat Bayar)`);

                } else if (now.isAfter(jatuhTempo) && ['0', '1'].includes(currentStatus)) {
                    // Jatuh tempo (status 2)
                    await trx.update({ status: '2' }, { transaction: t });
                    currentStatus = '2';
                    responsePayload.jatuh_tempo++;
                    responsePayload.created_at_jatuh_tempo.push(trx.createdAt);
                    console.log(`[Status Update] ${trx.id}: ${oldStatus} -> 2 (Jatuh Tempo)`);

                } else if (now.isAfter(reminderJT) && currentStatus === '0') {
                    // Reminder awal sebelum jatuh tempo
                    await trx.update({ status: '1' }, { transaction: t });
                    currentStatus = '1';
                    responsePayload.reminder_jatuh_tempo++;
                    responsePayload.created_at_reminder_jatuh_tempo.push(trx.createdAt);
                    console.log(`[Status Update] ${trx.id}: ${oldStatus} -> 1 (Reminder JT)`);

                } else if (now.isAfter(reminderTelat) && currentStatus === '2') {
                    // reminder telat, status tetap 2
                    responsePayload.reminder_telat_bayar++;
                    responsePayload.created_at_reminder_telat_bayar.push(trx.createdAt);
                    console.log(`[Reminder Telat] ${trx.id}: status tetap 2 (Reminder Telat Bayar)`);
                }
            }

            await t.commit();
            res.status(200).json({
                message: 'Update status transaksi selesai.',
                updated_counts: responsePayload
            });

        } catch (error) {
            if (t && !t.finished) {
                await t.rollback();
            }
            console.error("Error updateStatusTransaksi:", error);
            res.status(500).json({
                message: 'Terjadi kesalahan server.',
                error: error.message
            });
        }
    },

// UPDATES STATUS TRANSAKSI (bug 12 -> 5)
    async updateStatusTransaksi(req, res) {
        const {
            m_transactions,
            m_jatuh_tempo,
            m_log_transactions,
            m_lelang,
            m_jaminan,
            m_perpanjangan,
            d_perpanjangan
        } = req.models;

        const sequelize = m_transactions.sequelize;
        const Op = sequelize.Sequelize.Op;
        const t = await sequelize.transaction();

        try {
            const now = moment();

            const transactionsToProcess = await m_transactions.findAll({
                where: {
                    // hanya status '4' (tutup) dianggap final di pencarian awal
                    status: { [Op.notIn]: ['4'] }
                },
                order: [['createdAt', 'ASC']],
                transaction: t
            });

            let responsePayload = {
                tutup: 0,
                terjual: 0,
                lelang: 0,
                telat_bayar: 0,
                jatuh_tempo: 0,
                reminder_jatuh_tempo: 0,
                diperpanjang_menjadi_aktif: 0,
                reminder_telat_bayar: 0,
                dipusat: 0,
                created_at_tutup: [],
                created_at_terjual: [],
                created_at_lelang: [],
                created_at_telat_bayar: [],
                created_at_jatuh_tempo: [],
                created_at_reminder_jatuh_tempo: [],
                created_at_diperpanjang_menjadi_aktif: [],
                created_at_reminder_telat_bayar: [],
                created_at_dipusat: []
            };

            if (transactionsToProcess.length === 0) {
                await t.commit();
                return res.status(200).json({
                    message: 'Tidak ada transaksi yang perlu diperbarui.',
                    updated_counts: responsePayload
                });
            }

            for (const trx of transactionsToProcess) {
                const oldStatus = trx.status;
                let currentStatus = trx.status;

                // ===================================================================
                // 1) PELUNASAN — jika ada f_pelunasan, ubah ke status '4' (final)
                // ===================================================================
                if (trx.f_log_transactions) {
                    const log = await m_log_transactions.findByPk(trx.f_log_transactions, { transaction: t });
                    if (log && log.f_pelunasan != null && currentStatus !== '4') {
                        await trx.update({ status: '4' }, { transaction: t });
                        currentStatus = '4';

                        // Cleanup
                        await m_jatuh_tempo.destroy({ where: { f_transactions: trx.id }, transaction: t });
                        await m_lelang.destroy({ where: { f_transactions: trx.id }, transaction: t });

                        responsePayload.tutup++;
                        responsePayload.created_at_tutup.push(trx.createdAt);
                        console.log(`[Status Update] ${trx.id}: ${oldStatus} -> 4 (Tutup/Pelunasan)`);
                        continue;
                    }
                }

                // ===================================================================
                // 2) Ambil entitas LELANG & JAMINAN lebih awal (agar bisa di-cancel saat perpanjangan)
                // ===================================================================
                const lelang = await m_lelang.findOne({
                    where: { f_transactions: trx.id },
                    transaction: t
                });

                let jaminan = null;
                if (lelang) {
                    jaminan = await m_jaminan.findOne({
                        where: { f_lelang: lelang.id },
                        transaction: t
                    });
                }

                // ===================================================================
                // 3) HITUNG JATUH TEMPO EFEKTIF & CEK PERPANJANGAN (DIEVALUASI LEBIH AWAL)
                // ===================================================================
                let effectiveJatuhTempoDate = null;
                let isExtended = false;

                if (trx.f_log_transactions) {
                    const log = await m_log_transactions.findByPk(trx.f_log_transactions, { transaction: t });
                    if (log && log.f_perpanjangan) {
                        const latestDetail = await d_perpanjangan.findOne({
                            where: { f_perpanjangan: log.f_perpanjangan },
                            order: [['date_perpanjangan', 'DESC']],
                            transaction: t
                        });
                        if (latestDetail?.date_perpanjangan) {
                            effectiveJatuhTempoDate = moment(latestDetail.date_perpanjangan);
                            isExtended = true;
                        } else {
                            const master = await m_perpanjangan.findByPk(log.f_perpanjangan, { transaction: t });
                            if (master?.date_perpanjangan) {
                                effectiveJatuhTempoDate = moment(master.date_perpanjangan);
                                isExtended = true;
                            }
                        }
                    }
                }

                if (!effectiveJatuhTempoDate && trx.jatuh_tempo) {
                    effectiveJatuhTempoDate = moment(trx.jatuh_tempo);
                }

                // -------------------------------------------------------------------
                // 3a) Jika ADA perpanjangan & sekarang < jatuh tempo efektif:
                //     - Paksa status ke '0' (aktif), walaupun awalnya '5' (lelang)
                //     - Hapus m_jatuh_tempo
                //     - OPSIONAL: auto-cancel lelang (set flag cancel) agar tidak balik ke 5 lagi
                // -------------------------------------------------------------------
                if (isExtended && effectiveJatuhTempoDate && now.isBefore(effectiveJatuhTempoDate)) {
                    if (currentStatus !== '0') {
                        await trx.update({ status: '0' }, { transaction: t });
                        currentStatus = '0';
                    }

                    // bersihkan record jatuh_tempo
                    await m_jatuh_tempo.destroy({ where: { f_transactions: trx.id }, transaction: t });

                    // jika sebelumnya sedang dilelang, tandai cancel agar tidak memicu balik ke '5'
                    if (lelang && !lelang.status_cancel) {
                        await lelang.update({
                            status_cancel: '1',
                            time_cancel: now.format('YYYY-MM-DD HH:mm:ss'),
                            noted: (lelang.noted ? (lelang.noted + ' | ') : '') + 'Auto-cancel: perpanjangan aktif'
                        }, { transaction: t });
                    }

                    responsePayload.diperpanjang_menjadi_aktif++;
                    responsePayload.created_at_diperpanjang_menjadi_aktif.push(trx.createdAt);
                    console.log(`[Status Update] ${trx.id}: ${oldStatus} -> 0 (Aktif karena perpanjangan)`);
                    // Sangat penting: lanjut ke transaksi berikutnya, jangan proses logika lelang/waktu
                    continue;
                }

                // ===================================================================
                // 4) KOREKSI — jika status '6' tapi tidak ada lelang/jaminan => jadikan '3'
                // ===================================================================
                if ((!lelang || !jaminan) && currentStatus === '6') {
                    console.log(`[Fix] ${trx.id}: status 6 tanpa lelang/jaminan — koreksi ke telat bayar (3)`);
                    await m_jatuh_tempo.destroy({ where: { f_transactions: trx.id }, transaction: t });
                    await trx.update({ status: '3' }, { transaction: t });
                    currentStatus = '3';
                    responsePayload.telat_bayar++;
                    responsePayload.created_at_telat_bayar.push(trx.createdAt);
                    continue;
                }

                // ===================================================================
                // 5) LOGIKA BERDASARKAN STATUS JAMINAN
                // ===================================================================
                if (jaminan) {
                    const jamStatus = (jaminan.status || '').toLowerCase();

                    // TERJUAL -> '6'
                    if (jamStatus === 'terjual' && currentStatus !== '6') {
                        await trx.update({ status: '6' }, { transaction: t });
                        currentStatus = '6';
                        responsePayload.terjual++;
                        responsePayload.created_at_terjual.push(trx.createdAt);
                        console.log(`[Status Update] ${trx.id}: ${oldStatus} -> 6 (Terjual)`);
                        continue;
                    }

                    // DI PUSAT -> '8'
                    if (['dikirim', 'diterima'].includes(jamStatus) && currentStatus !== '8') {
                        await trx.update({ status: '8' }, { transaction: t });
                        currentStatus = '8';
                        responsePayload.dipusat++;
                        responsePayload.created_at_dipusat.push(trx.createdAt);
                        console.log(`[Status Update] ${trx.id}: ${oldStatus} -> 8 (Di Pusat)`);
                        continue;
                    }

                    // DIKEMBALIKAN -> '5'
                    if (jamStatus === 'dikembalikan' && currentStatus !== '5') {
                        await trx.update({ status: '5' }, { transaction: t });
                        currentStatus = '5';
                        responsePayload.lelang++;
                        responsePayload.created_at_lelang.push(trx.createdAt);
                        console.log(`[Status Update] ${trx.id}: ${oldStatus} -> 5 (Dikembalikan)`);
                        await m_jatuh_tempo.destroy({ where: { f_transactions: trx.id }, transaction: t });
                        continue;
                    }
                }

                // Jika hanya ada LELANG (belum ada jaminan) -> '5'
                if (lelang && !jaminan && !['5', '6', '8'].includes(currentStatus)) {
                    await trx.update({ status: '5' }, { transaction: t });
                    currentStatus = '5';
                    responsePayload.lelang++;
                    responsePayload.created_at_lelang.push(trx.createdAt);
                    console.log(`[Status Update] ${trx.id}: ${oldStatus} -> 5 (Lelang tanpa jaminan)`);
                    await m_jatuh_tempo.destroy({ where: { f_transactions: trx.id }, transaction: t });
                    continue;
                }

                // Final/stop-set states (kecuali '5' sudah ditangani di atas, dan
                // perpanjangan sudah ditangani lebih awal)
                if (['4', '6', '8', '5'].includes(currentStatus)) {
                    continue;
                }

                // ===================================================================
                // 6) LOGIKA BERDASARKAN WAKTU (REMINDER JT, JT, TELAT, REMINDER TELAT)
                // ===================================================================
                if (!effectiveJatuhTempoDate) {
                    // Tidak punya tanggal jatuh tempo, skip
                    continue;
                }

                const reminderJT = effectiveJatuhTempoDate.clone().subtract(3, 'days');
                const jatuhTempo = effectiveJatuhTempoDate;
                const reminderTelat = effectiveJatuhTempoDate.clone().add(13, 'days');
                const telat = effectiveJatuhTempoDate.clone().add(15, 'days');

                if (now.isAfter(telat) && currentStatus !== '3') {
                    await trx.update({ status: '3' }, { transaction: t });
                    currentStatus = '3';

                    await m_jatuh_tempo.findOrCreate({
                        where: { f_transactions: trx.id },
                        defaults: {
                            f_transactions: trx.id,
                            status: 'active',
                            date: jatuhTempo.format('YYYY-MM-DD HH:mm:ss')
                        },
                        transaction: t
                    });

                    responsePayload.telat_bayar++;
                    responsePayload.created_at_telat_bayar.push(trx.createdAt);
                    console.log(`[Status Update] ${trx.id}: ${oldStatus} -> 3 (Telat Bayar)`);

                } else if (now.isAfter(jatuhTempo) && ['0', '1'].includes(currentStatus)) {
                    await trx.update({ status: '2' }, { transaction: t });
                    currentStatus = '2';
                    responsePayload.jatuh_tempo++;
                    responsePayload.created_at_jatuh_tempo.push(trx.createdAt);
                    console.log(`[Status Update] ${trx.id}: ${oldStatus} -> 2 (Jatuh Tempo)`);

                } else if (now.isAfter(reminderJT) && currentStatus === '0') {
                    await trx.update({ status: '1' }, { transaction: t });
                    currentStatus = '1';
                    responsePayload.reminder_jatuh_tempo++;
                    responsePayload.created_at_reminder_jatuh_tempo.push(trx.createdAt);
                    console.log(`[Status Update] ${trx.id}: ${oldStatus} -> 1 (Reminder JT)`);

                } else if (now.isAfter(reminderTelat) && currentStatus === '2') {
                    responsePayload.reminder_telat_bayar++;
                    responsePayload.created_at_reminder_telat_bayar.push(trx.createdAt);
                    console.log(`[Reminder Telat] ${trx.id}: status tetap 2 (Reminder Telat Bayar)`);
                }
            }

            await t.commit();
            res.status(200).json({
                message: 'Update status transaksi selesai.',
                updated_counts: responsePayload
            });

        } catch (error) {
            if (t && !t.finished) {
                await t.rollback();
            }
            console.error("Error updateStatusTransaksi:", error);
            res.status(500).json({
                message: 'Terjadi kesalahan server.',
                error: error.message
            });
        }
    },

// pengecekan pembuatan transaksi 16-10-2025
if (instance.f_customers && instance.f_types) {
    
    const { m_transactions, m_log_transactions } = instance.constructor.sequelize.models;

    // Daftar status yang dianggap sebagai pinjaman aktif/berjalan.
    const activeStasuses = ['0', '1', '2', '3', '4', '5', '6', '7'];

    // Cari semua transaksi historis milik nasabah, termasuk data log pelunasannya.
    const allCustomerTransactions = await m_transactions.findAll({
        where: {
            f_customers: instance.f_customers,
        },
        include: [{
            model: m_log_transactions,
            as: 'm_log_transaction',
            attributes: ['f_pelunasan'],
            required: false // Gunakan LEFT JOIN agar transaksi tanpa log tetap muncul
        }],
        transaction: options.transaction
    });
    
    // Filter untuk mendapatkan transaksi yang benar-benar aktif saat ini.
    const trulyActiveTransactions = allCustomerTransactions.filter(tx => {
        // Abaikan transaksi yang pernah dibuat namun ditolak.
        const isRejected = tx.status_approval === '3' || tx.status_pinjaman === 'reject_bm';
        if (isRejected) {
            return false;
        }
        
        // Kondisi 1: Status transaksi harus termasuk dalam daftar status aktif.
        const isStatusActive = activeStasuses.includes(tx.status);
        
        // Kondisi 2: Transaksi dianggap "belum lunas".
        // Ini secara langsung memenuhi permintaan Anda untuk memastikan transaksi yang
        // f_pelunasan-nya tidak null (sudah lunas) tidak dihitung sebagai aktif.
        const isNotPaidOff = !tx.m_log_transaction || tx.m_log_transaction.f_pelunasan === null;
        
        // Sebuah transaksi dihitung "aktif" jika statusnya aktif DAN belum lunas.
        return isStatusActive && isNotPaidOff;
    });
    
    // =====================================================================================
    // LOGIKA BARU: Cek duplikasi barang berdasarkan IMEI untuk transaksi yang sedang aktif
    // =====================================================================================
    // Pengecekan ini hanya berjalan jika pengajuan baru menyertakan IMEI.
    if (instance.IMEI) {
        const duplicateImeiTransaction = trulyActiveTransactions.find(
            tx => tx.IMEI === instance.IMEI && tx.f_types === instance.f_types
        );
        
        // Jika ditemukan transaksi aktif dengan tipe dan IMEI yang sama, tolak pengajuan.
        if (duplicateImeiTransaction) {
            throw new Error(`Barang dengan IMEI ${instance.IMEI} sudah memiliki pinjaman yang masih aktif dan belum lunas.`);
        }
    }
    // =====================================================================================
    
    
    // Cek Batasan Total Maksimal 5 Barang Aktif
    const totalCount = trulyActiveTransactions.length;
    if (totalCount >= 5) {
        throw new Error(`Batas maksimal pengajuan adalah 5 barang aktif per nasabah. Anda saat ini memiliki ${totalCount} barang aktif.`);
    }
    
    // Hitung dan Cek Batasan Maksimal 2 Barang dengan Tipe yang Sama
    const sameTypeCount = trulyActiveTransactions.filter(
        tx => tx.f_types === instance.f_types
    ).length;
    
    if (sameTypeCount >= 2) {
        throw new Error(`Batas maksimal pengajuan untuk tipe barang ini adalah 2 unit. Anda sudah memiliki ${sameTypeCount} unit aktif.`);
    }
}

// pengecekan pembuatan transaksi 18-10-2025
if (instance.f_customers && instance.f_types) {

        const { m_transactions, m_log_transactions } = instance.constructor.sequelize.models;

        // Daftar status yang dianggap sebagai pinjaman aktif/berjalan.
        const activeStasuses = ['0', '1', '2', '3', '4', '5', '6', '7'];

        // Cari semua transaksi historis milik nasabah, termasuk data log pelunasannya.
        const allCustomerTransactions = await m_transactions.findAll({
            where: {
                f_customers: instance.f_customers,
            },
            include: [{
                model: m_log_transactions,
                as: 'm_log_transaction',
                attributes: ['f_pelunasan'],
                required: false // Gunakan LEFT JOIN agar transaksi tanpa log tetap muncul
            }],
            transaction: options.transaction
        });

        // Filter untuk mendapatkan transaksi yang benar-benar aktif saat ini.
        const trulyActiveTransactions = allCustomerTransactions.filter(tx => {
            // Abaikan transaksi yang pernah dibuat namun ditolak.
            const isRejected = tx.status_approval === '3' || tx.status_pinjaman === 'reject_bm';
            if (isRejected) {
                return false;
            }

            // Kondisi 1: Status transaksi harus termasuk dalam daftar status aktif.
            const isStatusActive = activeStasuses.includes(String(tx.status));

            // Kondisi 2: Transaksi dianggap "belum lunas".
            const isNotPaidOff = !tx.m_log_transaction || tx.m_log_transaction.f_pelunasan === null;

            // Sebuah transaksi dihitung "aktif" jika statusnya aktif DAN belum lunas.
            return isStatusActive && isNotPaidOff;
        });

        // =====================================================================================
        // LOGIKA Cek duplikasi barang berdasarkan IMEI untuk transaksi yang sedang aktif
        // =====================================================================================
        // Pengecekan ini hanya berjalan jika pengajuan baru menyertakan IMEI.
        if (instance.IMEI) {
            const duplicateImeiTransaction = trulyActiveTransactions.find(tx => {
                const sameImei = String(tx.IMEI) === String(instance.IMEI);
                const sameType = String(tx.f_types) === String(instance.f_types);
                const statusIs4 = String(tx.status) === '4';
                // anggap bentrok HANYA jika: IMEI sama + tipe sama + status BUKAN '4'
                return sameImei && sameType && !statusIs4;
            });

            if (duplicateImeiTransaction) {
                throw new Error(`Barang dengan IMEI ${instance.IMEI} masih memiliki transaksi yang masih aktif atau belum lunas.`);
            }
        }
        // =====================================================================================

        // Cek Batasan Total Maksimal 5 Barang Aktif
        const totalCount = trulyActiveTransactions.length;
        if (totalCount >= 5) {
            throw new Error(`Batas maksimal pengajuan adalah 5 barang aktif per nasabah. Anda saat ini memiliki ${totalCount} barang aktif.`);
        }

        // Hitung dan Cek Batasan Maksimal 2 Barang dengan Tipe yang Sama
        const sameTypeCount = trulyActiveTransactions.filter(
            tx => String(tx.f_types) === String(instance.f_types)
        ).length;

        if (sameTypeCount >= 2) {
            throw new Error(`Batas maksimal pengajuan untuk tipe barang ini adalah 2 unit. Anda sudah memiliki ${sameTypeCount} unit aktif.`);
        }
    }