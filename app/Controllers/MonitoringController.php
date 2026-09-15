<?php

namespace App\Controllers;

use App\Core\Controller;
use App\Core\Database;
use mysqli;

class MonitoringController extends Controller
{
    private mysqli $conn;

    public function __construct()
    {
        parent::__construct();
        $this->conn = Database::getConnection();
    }

    /**
     * Factory Floor Real-Time Production Monitoring Display (formerly production_monitoring.php)
     */
    public function production(): void
    {
        $this->requireRole(['slitting', 'mkl3', 'admin', 'officer', 'qc']);

        $this->render('monitoring/production', [
            'conn' => $this->conn,
        ]);
    }

    /**
     * Factory Floor Production Monitoring Live AJAX Feed (formerly production_monitoring_ajax.php)
     */
    public function productionAjax(): void
    {
        $this->requireRole(['slitting', 'mkl3', 'admin', 'officer', 'qc']);

        $conn = $this->conn;
        require 'c:/laragon/www/Slitting_System_2.0/production_monitoring_ajax.php';
    }

    /**
     * Officer Weekly/Daily Shift Target Monitor (formerly officer_production_monitor.php)
     */
    public function officer(): void
    {
        $this->requireRole(['slitting', 'mkl3', 'admin', 'officer', 'qc']);

        $conn = $this->conn;
        $this->render('monitoring/officer', [
            'conn' => $this->conn,
        ]);
    }

    /**
     * Officer Shift Target Live AJAX Feed (formerly officer_production_ajax.php)
     */
    public function officerAjax(): void
    {
        $this->requireRole(['slitting', 'mkl3', 'admin', 'officer', 'qc']);

        $conn = $this->conn;
        require 'c:/laragon/www/Slitting_System_2.0/officer_production_ajax.php';
    }
}
