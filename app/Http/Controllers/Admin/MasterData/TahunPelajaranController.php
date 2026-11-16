<?php

namespace App\Http\Controllers\Admin\MasterData;

use App\Http\Controllers\Controller;
use App\Models\mst_thn_aka;
use App\Models\ValidationMessage;
use Exception;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

class TahunPelajaranController extends Controller
{
    public string $title = 'Master Data';
    public string $mainTitle = 'Tahun Akademik';
    public string $dataTitle = 'Tahun Akademik';

    public function index()
    {
        $data['title'] = $this->title;
        $data['mainTitle'] = $this->mainTitle;
        $data['dataTitle'] = $this->dataTitle;
        $data['columnsUrl'] = route('admin.master-data.tahun-pelajaran.get-column');
        $data['datasUrl'] = route('admin.master-data.tahun-pelajaran.get-data');

        return view('admin.master_data.tahun_akademik.index', $data);
    }

    public function getColumn()
    {
        return [
            ['data' => null, 'name' => 'no', 'className' => 'text-center', 'columnType' => 'no'],
            ['data' => 'thn_aka', 'name' => 'Tahun Pelajaran', 'searchable' => true, 'orderable' => true],
        ];
    }

    public function getData(Request $request)
    {
        $draw = $request->get('draw');
        $start = $request->get('start');
        $rowperpage = $request->get('length');

        $columnIndex_arr = $request->get('order', []);
        $columnName_arr = $request->get('columns', []);
        $order_arr = $request->get('order', []);
        $search_arr = $request->get('search', []);
        $searchValue = $search_arr['value'] ?? '';

        $columnName = 'thn_aka';
        $columnSortOrder = 'desc';

        if (!empty($order_arr)) {
            $columnIndex = $columnIndex_arr[0]['column'] ?? null;
            if ($columnIndex !== null && !empty($columnName_arr[$columnIndex]['data']) && $columnName_arr[$columnIndex]['data'] !== 'no') {
                $columnName = $columnName_arr[$columnIndex]['data'];
                $columnSortOrder = $order_arr[0]['dir'] ?? 'desc';
            }
        }

        $totalRecords = mst_thn_aka::query()->count();
        $totalRecordswithFilter = mst_thn_aka::query()
            ->where('thn_aka', 'like', '%' . $searchValue . '%')
            ->count();

        $records = mst_thn_aka::query()
            ->orderBy($columnName, $columnSortOrder)
            ->where('thn_aka', 'like', '%' . $searchValue . '%')
            ->skip($start)
            ->take($rowperpage)
            ->get()
            ->toArray();

        return response()->json([
            'draw' => intval($draw),
            'recordsTotal' => $totalRecords,
            'recordsFiltered' => $totalRecordswithFilter,
            'data' => $records,
        ]);
    }

    public function store(Request $request)
    {
        $normalized = $this->normalizeThnAka(
            (string) $request->input('thn_aka', ''),
            (string) $request->input('semester', '')
        );

        if ($normalized === null) {
            return response()->json([
                'message' => 'Format Tahun Akademik salah. Contoh: 2025/2026 atau 2025/2026 - GANJIL / GENAP',
                'errors' => [
                    'thn_aka' => ['Gunakan format 2025/2026, boleh ditambah GANJIL atau GENAP.'],
                ],
            ], 422);
        }

        $request->merge(['thn_aka' => $normalized]);

        $validator = Validator::make(
            $request->all(),
            [
                'thn_aka' => [
                    'required',
                    'regex:/^\d{4}\/\d{4}(?: - (GANJIL|GENAP))?$/',
                    function ($attribute, $value, $fail) {
                        if (strlen((string) $value) > 20) {
                            $fail('Tahun Pelajaran tidak boleh lebih dari 20 karakter');
                        }
                    },
                ],
            ],
            array_merge(ValidationMessage::messages(), [
                'thn_aka.regex' => 'Format Tahun Akademik salah. Contoh: 2025/2026 atau 2025/2026 - GANJIL',
            ]),
            ValidationMessage::attributes()
        );

        if ($validator->fails()) {
            return response()->json([
                'message' => $validator->errors()->first(),
                'errors' => $validator->errors(),
            ], 422);
        }

        if (mst_thn_aka::where('thn_aka', $normalized)->exists()) {
            return response()->json(['message' => 'Tahun Pelajaran sudah ada'], 422);
        }

        try {
            DB::connection('DATA_MYSQL')->beginTransaction();

            $payload = ['thn_aka' => $normalized];
            if ($this->hasUrutColumn()) {
                $payload['urut'] = ((int) mst_thn_aka::query()->max('urut')) + 1;
            }

            mst_thn_aka::create($payload);

            DB::connection('DATA_MYSQL')->commit();

            return response()->json(['message' => 'Data ' . $this->mainTitle . ' telah disimpan']);
        } catch (Exception $e) {
            DB::connection('DATA_MYSQL')->rollBack();

            return response()->json([
                'message' => 'Data ' . $this->mainTitle . ' gagal disimpan',
                'error' => config('app.debug') ? $e->getMessage() : null,
            ], 422);
        }
    }

    /**
     * Terima berbagai input lalu normalkan ke:
     * 2025/2026  atau  2025/2026 - GANJIL|GENAP
     */
    private function normalizeThnAka(string $rawThnAka, string $semester = ''): ?string
    {
        $value = trim($rawThnAka);
        $semester = strtoupper(trim($semester));

        if ($value === '') {
            return null;
        }

        // Sudah lengkap di satu field: "2025/2026 - ganjil", "2025-2026 genap", dll.
        if (preg_match('/^(\d{4})\s*[\/\-]\s*(\d{4})(?:\s*[-–]?\s*(ganjil|genap))?$/iu', $value, $matches)) {
            $normalized = $matches[1] . '/' . $matches[2];
            $fromText = isset($matches[3]) ? strtoupper($matches[3]) : '';
            $semesterLabel = in_array($semester, ['GANJIL', 'GENAP'], true)
                ? $semester
                : (in_array($fromText, ['GANJIL', 'GENAP'], true) ? $fromText : '');

            if ($semesterLabel !== '') {
                $normalized .= ' - ' . $semesterLabel;
            }

            return $normalized;
        }

        // Hanya tahun awal "2025" + semester dari dropdown
        if (preg_match('/^(\d{4})$/', $value, $matches) && in_array($semester, ['GANJIL', 'GENAP'], true)) {
            $start = (int) $matches[1];

            return $start . '/' . ($start + 1) . ' - ' . $semester;
        }

        // Hanya "2025/2026" + semester dari dropdown
        if (preg_match('/^(\d{4})\s*[\/\-]\s*(\d{4})$/', $value, $matches)) {
            $normalized = $matches[1] . '/' . $matches[2];
            if (in_array($semester, ['GANJIL', 'GENAP'], true)) {
                $normalized .= ' - ' . $semester;
            }

            return $normalized;
        }

        return null;
    }

    private function hasUrutColumn(): bool
    {
        static $cached = null;
        if ($cached !== null) {
            return $cached;
        }

        try {
            $cached = \Illuminate\Support\Facades\Schema::connection('DATA_MYSQL')->hasColumn('mst_thn_aka', 'urut');
        } catch (\Throwable) {
            $cached = true;
        }

        return $cached;
    }
}
