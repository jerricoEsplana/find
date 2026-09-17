<?php
require 'config.php'; require_admin();
header('Content-Type: text/csv; charset=utf-8');
header('Content-Disposition: attachment; filename="findit-reports-' . date('Y-m-d') . '.csv"');
$out=fopen('php://output','w');
fputcsv($out,['Report ID','Type','Item','Category','Reporter','Date','Location','Report Status','Item Status','Created At']);
$stmt=$pdo->query("SELECT r.*,i.item_name,i.category,u.full_name FROM reports r JOIN items i ON i.id=r.item_id JOIN users u ON u.id=r.user_id ORDER BY r.created_at DESC");
while($r=$stmt->fetch())fputcsv($out,['RPT-'.str_pad((string)$r['id'],4,'0',STR_PAD_LEFT),ucfirst($r['report_type']),$r['item_name'],$r['category'],$r['full_name'],$r['event_date'],$r['campus_location'],status_label($r['report_status']),status_label($r['item_status']),$r['created_at']]);
fclose($out);exit;
