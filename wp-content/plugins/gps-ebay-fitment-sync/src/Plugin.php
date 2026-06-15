<?php

declare(strict_types=1);

namespace GPS_Ebay_Fitment_Sync;

use GPS_Ebay_Fitment_Sync\Admin\AdminPage;
use GPS_Ebay_Fitment_Sync\Database\Database;
use GPS_Ebay_Fitment_Sync\Service\ApifyClient;
use GPS_Ebay_Fitment_Sync\Service\AuditCsvExporter;
use GPS_Ebay_Fitment_Sync\Service\EbayFitmentPreview;
use GPS_Ebay_Fitment_Sync\Service\EbayFitmentLiveTest;
use GPS_Ebay_Fitment_Sync\Service\EbayInventoryFitmentBatchRunner;
use GPS_Ebay_Fitment_Sync\Service\EbayInventoryRemapAudit;
use GPS_Ebay_Fitment_Sync\Service\FitmentLookupService;
use GPS_Ebay_Fitment_Sync\Service\KTypeBackfillAutoRunner;
use GPS_Ebay_Fitment_Sync\Service\KTypeMissAudit;
use GPS_Ebay_Fitment_Sync\Service\OemKtypeEbayCoverageAudit;
use GPS_Ebay_Fitment_Sync\Service\ProductScanner;
use GPS_Ebay_Fitment_Sync\Service\VehicleContextKtypeInferenceAudit;
use GPS_Ebay_Fitment_Sync\Support\PartNumberCandidateValidator;
use GPS_Ebay_Fitment_Sync\Support\PartNumberNormalizer;
use GPS_Ebay_Fitment_Sync\Support\Settings;

final class Plugin
{
    private static ?self $instance = null;

    public static function instance(): self
    {
        if (!self::$instance) {
            self::$instance = new self();
        }

        return self::$instance;
    }

    public function init(): void
    {
        $settings = new Settings();
        $settings->hooks();

        if (!is_admin()) {
            return;
        }

        Database::maybe_upgrade();

        $normalizer = new PartNumberNormalizer();
        $validator = new PartNumberCandidateValidator($normalizer);
        $database = new Database($normalizer);
        $client = new ApifyClient($settings);
        $lookup = new FitmentLookupService($database, $client, $normalizer, $settings, $validator);
        $scanner = new ProductScanner($database, $normalizer, $settings, $validator);
        $auditCsvExporter = new AuditCsvExporter($database);
        $autoRunner = new KTypeBackfillAutoRunner($scanner, $lookup, $database, $auditCsvExporter, $settings);
        $ebayFitmentPreview = new EbayFitmentPreview();
        $ebayFitmentLiveTest = new EbayFitmentLiveTest($ebayFitmentPreview);
        $ebayInventoryRemapAudit = new EbayInventoryRemapAudit($ebayFitmentPreview);
        $ebayInventoryFitmentBatchRunner = new EbayInventoryFitmentBatchRunner($ebayFitmentPreview, $ebayInventoryRemapAudit);
        $oemKtypeEbayCoverageAudit = new OemKtypeEbayCoverageAudit($scanner, $ebayFitmentPreview);
        $ktypeMissAudit = new KTypeMissAudit($scanner);
        $vehicleContextKtypeInferenceAudit = new VehicleContextKtypeInferenceAudit();

        (new AdminPage($settings, $lookup, $scanner, $database, $auditCsvExporter, $autoRunner, $ebayFitmentPreview, $ebayFitmentLiveTest, $ebayInventoryFitmentBatchRunner, $ebayInventoryRemapAudit, $oemKtypeEbayCoverageAudit, $ktypeMissAudit, $vehicleContextKtypeInferenceAudit))->hooks();
    }

    public static function deactivate(): void
    {
        // Data is intentionally retained. Cached KTypes are canonical product/part-number data.
    }
}
