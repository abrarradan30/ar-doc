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