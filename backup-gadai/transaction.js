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