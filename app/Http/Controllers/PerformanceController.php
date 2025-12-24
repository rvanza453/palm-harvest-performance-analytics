<?php

namespace App\Http\Controllers;

use App\Models\KaryawanPerformance;
use App\Models\UploadBatch;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

class PerformanceController extends Controller
{
    /**
     * Display the admin dashboard
     */
    public function index()
    {
        return view('admin.index');
    }

    /**
     * Display the analysis dashboard
     */
    public function dashboard()
    {
        return view('dashboard.index');
    }

    /**
     * Get all upload batches
     */
    public function getBatches(Request $request)
    {
        $batches = UploadBatch::withCount('performances')
            ->orderBy('created_at', 'desc')
            ->paginate($request->get('per_page', 50));

        return response()->json($batches);
    }

    /**
     * Get filtered performance data
     */
    public function getData(Request $request)
    {
        $filters = $request->only([
            'periode',
            'afd',
            'batch_id',
            'start_date',
            'end_date',
        ]);

        $result = KaryawanPerformance::getCategorizedData($filters);

        return response()->json($result);
    }

    /**
     * Get available filters (periods, AFDs, batches)
     */
    public function getFilters()
    {
        $filters = [
            'periodes' => KaryawanPerformance::select('periode')
                ->distinct()
                ->whereNotNull('periode')
                ->orderBy('periode', 'desc')
                ->pluck('periode'),
            'afds' => KaryawanPerformance::select('afd')
                ->distinct()
                ->whereNotNull('afd')
                ->orderBy('afd')
                ->pluck('afd'),
            'batches' => UploadBatch::select('batch_id', 'filename', 'periode', 'created_at')
                ->orderBy('created_at', 'desc')
                ->get(),
        ];

        return response()->json($filters);
    }

    /**
     * Upload and process Excel file
     */
    public function upload(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'file' => 'required|file|mimes:xlsx,xls|max:10240',
            'periode' => 'nullable|string',
            'notes' => 'nullable|string',
            'bjr' => 'nullable|numeric|min:1',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Validasi gagal',
                'errors' => $validator->errors(),
            ], 422);
        }

        try {
            $file = $request->file('file');
            $periode = $request->input('periode', date('Y-m'));
            $notes = $request->input('notes');
            $bjr = $request->input('bjr', 15.0);
            $uploadedBy = $request->input('uploaded_by', 'System');

            // Read Excel file
            $reader = \PhpOffice\PhpSpreadsheet\IOFactory::createReaderForFile($file->getRealPath());
            $spreadsheet = $reader->load($file->getRealPath());
            $worksheet = $spreadsheet->getActiveSheet();
            $rows = $worksheet->toArray();

            // Parse header
            $header = array_map(function($h) {
                return strtoupper(trim($h));
            }, $rows[0]);

            // Generate batch ID
            $batchId = UploadBatch::generateBatchId();
            
            $dataToInsert = [];
            $successCount = 0;
            $errorRows = [];

            // Process data rows
            for ($i = 1; $i < count($rows); $i++) {
                $row = $rows[$i];
                
                // Map columns
                $rowData = [];
                foreach ($header as $idx => $col) {
                    $rowData[$col] = $row[$idx] ?? null;
                }

                // Extract required fields
                $nama = $rowData['NAMA'] ?? null;
                if (!$nama) continue;

                $nik = $rowData['ID'] ?? $rowData['NIK'] ?? null;
                $afd = $rowData['AFD'] ?? $rowData['AFDELING'] ?? $rowData['DIVISI'] ?? '-';
                
                // Parse numeric values
                $hk = $this->parseNumeric($rowData['HK'] ?? $rowData['HARI_KERJA'] ?? 0);
                $jjg = $this->parseNumeric($rowData['JJG'] ?? $rowData['JANJANG'] ?? $rowData['TOTAL_JJG'] ?? $rowData['TOTAL_JANJANG'] ?? 0);
                
                // Calculate TON and Kg/HK
                $totalKg = $jjg * $bjr;
                $ton = $totalKg / 1000;
                
                // Override if TON or KG provided
                if (isset($rowData['TON']) || isset($rowData['TONASE'])) {
                    $inputTon = $this->parseNumeric($rowData['TON'] ?? $rowData['TONASE'] ?? 0);
                    if ($inputTon > 0) {
                        $ton = $inputTon;
                        $totalKg = $ton * 1000;
                    }
                }
                
                if (isset($rowData['KG'])) {
                    $inputKg = $this->parseNumeric($rowData['KG']);
                    if ($inputKg > 0) {
                        $totalKg = $inputKg;
                        $ton = $inputKg / 1000;
                    }
                }

                // Calculate productivity
                $kgPerHK = $hk > 0 ? $totalKg / $hk : 0;
                
                // Override if PROD provided
                if (isset($rowData['PROD']) || isset($rowData['PRODUKTIVITAS']) || isset($rowData['KG/HK']) || isset($rowData['KG_PER_HK'])) {
                    $inputProd = $this->parseNumeric(
                        $rowData['PROD'] ?? 
                        $rowData['PRODUKTIVITAS'] ?? 
                        $rowData['KG/HK'] ?? 
                        $rowData['KG_PER_HK'] ?? 0
                    );
                    if ($inputProd > 0) {
                        $kgPerHK = $inputProd;
                    }
                }

                if ($hk <= 0 || $jjg <= 0) {
                    $errorRows[] = $i + 1;
                    continue;
                }

                $dataToInsert[] = [
                    'nik' => $nik,
                    'nama' => $nama,
                    'afd' => $afd,
                    'hk' => $hk,
                    'jjg' => $jjg,
                    'ton' => $ton,
                    'kg_per_hk' => $kgPerHK,
                    'periode' => $periode,
                    'tanggal_upload' => now(),
                    'uploaded_by' => $uploadedBy,
                    'batch_id' => $batchId,
                    'notes' => $notes,
                    'created_at' => now(),
                    'updated_at' => now(),
                ];

                $successCount++;
            }

            if (empty($dataToInsert)) {
                return response()->json([
                    'success' => false,
                    'message' => 'Tidak ada data valid yang ditemukan dalam file Excel',
                ], 400);
            }

            // Insert to database
            DB::transaction(function () use ($dataToInsert, $batchId, $file, $periode, $uploadedBy, $notes, $bjr) {
                // Insert batch record
                UploadBatch::create([
                    'batch_id' => $batchId,
                    'filename' => $file->getClientOriginalName(),
                    'periode' => $periode,
                    'total_records' => count($dataToInsert),
                    'uploaded_by' => $uploadedBy,
                    'notes' => $notes,
                    'metadata' => [
                        'bjr' => $bjr,
                    ],
                ]);

                // Insert performance data in chunks
                foreach (array_chunk($dataToInsert, 500) as $chunk) {
                    KaryawanPerformance::insert($chunk);
                }
            });

            return response()->json([
                'success' => true,
                'message' => "Berhasil mengupload {$successCount} data karyawan",
                'data' => [
                    'batch_id' => $batchId,
                    'total_records' => $successCount,
                    'error_rows' => $errorRows,
                ],
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Gagal memproses file: ' . $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Delete a batch and its related data
     */
    public function deleteBatch($batchId)
    {
        try {
            DB::transaction(function () use ($batchId) {
                KaryawanPerformance::where('batch_id', $batchId)->delete();
                UploadBatch::where('batch_id', $batchId)->delete();
            });

            return response()->json([
                'success' => true,
                'message' => 'Batch berhasil dihapus',
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Gagal menghapus batch: ' . $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Get statistics
     */
    public function getStats()
    {
        $stats = [
            'total_records' => KaryawanPerformance::count(),
            'total_batches' => UploadBatch::count(),
            'total_karyawan' => KaryawanPerformance::distinct('nama')->count('nama'),
            'latest_upload' => UploadBatch::latest()->first(),
            'periodes' => KaryawanPerformance::select('periode', DB::raw('count(*) as total'))
                ->groupBy('periode')
                ->orderBy('periode', 'desc')
                ->get(),
        ];

        return response()->json($stats);
    }

    /**
     * Parse numeric value from string (handle Indonesian format)
     */
    private function parseNumeric($value)
    {
        if (is_numeric($value)) {
            return floatval($value);
        }

        if (!$value) {
            return 0;
        }

        $str = trim($value);
        $str = preg_replace('/[^\d,.-]/', '', $str);

        // Handle Indonesian format (1.234,56)
        if (strpos($str, ',') !== false) {
            return floatval(str_replace(['.', ','], ['', '.'], $str));
        }

        // Handle dot format
        if (strpos($str, '.') !== false) {
            $parts = explode('.', $str);
            if (count($parts) > 1 && strlen($parts[count($parts) - 1]) === 3) {
                // 1.234 format (thousands separator)
                return floatval(str_replace('.', '', $str));
            }
        }

        return floatval($str);
    }
}

