public function scopeGetSOForSJ($query)
{
return $query
->from('t_sales_order')
->select(
't_sales_order.id as t_sales_order_id',
't_sales_order.*',
'customer.*',
'item.*',
// trading false
\DB::raw('COALESCE(tsj_summary.total_berat_kirim, 0) as berat_kirim'),
// trading true
\DB::raw('COALESCE(tptd_summary.total_berat_si, 0) as berat_trading'),
// berat outstanding = berat SO - total berat kirim (SJ / SI)
\DB::raw('CAST(ROUND(
CASE
WHEN tsi_summary.total_berat_si IS NOT NULL
THEN t_sales_order.berat - COALESCE(tsi_summary.total_berat_si, 0)
ELSE t_sales_order.berat - COALESCE(tsj_summary.total_berat_kirim, 0)
END
) AS INTEGER) as berat_os')
)
->leftJoin('m_customer as customer', 't_sales_order.m_customer_id', '=', 'customer.id')
->leftJoin('m_item as item', 't_sales_order.m_item_id', '=', 'item.id')
->leftJoin(
\DB::raw('(
SELECT
t_sales_order_id,
SUM(berat_kirim) as total_berat_kirim
FROM t_surat_jalan
GROUP BY t_sales_order_id
) as tsj_summary'),
'tsj_summary.t_sales_order_id',
'=',
't_sales_order.id'
)
->leftJoin(
\DB::raw('(
SELECT
t_pi_bahan.t_sales_order_id,
SUM(t_pi_trading_d.berat_si) as total_berat_si
FROM t_pi_bahan
JOIN t_pi_trading_d ON t_pi_trading_d.t_pi_bahan_id = t_pi_bahan.id
WHERE t_pi_bahan.is_trading = true
GROUP BY t_pi_bahan.t_sales_order_id
) as tptd_summary'),
'tptd_summary.t_sales_order_id',
'=',
't_sales_order.id'
)
// tambahkan LEFT JOIN untuk summary SI
->leftJoin(
\DB::raw('(
SELECT
si.t_sales_order_id,
SUM(sid.berat_si) as total_berat_si
FROM t_sales_invoice_d sid
JOIN t_sales_invoice si ON si.id = sid.t_sales_invoice_id
GROUP BY si.t_sales_order_id
) as tsi_summary'),
'tsi_summary.t_sales_order_id',
'=',
't_sales_order.id'
);
}