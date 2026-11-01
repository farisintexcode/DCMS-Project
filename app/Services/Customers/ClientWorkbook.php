<?php

namespace App\Services\Customers;

use App\Actions\Imports\ImportWorkflow;
use App\Services\Imports\MalwareScanner;
use App\Services\Imports\WorkbookReader;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use RuntimeException;

/** M12 bulk client onboarding: fail-closed scan, then the hardened M04 reader on the first sheet with headers in row 1. */
class ClientWorkbook
{
    /** @var array<string, array<int, string>> canonical field => accepted normalized headers */
    private const HEADERS = [
        'code' => ['code', 'client code', 'kod', 'kod klien'],
        'name' => ['name', 'client name', 'nama', 'nama klien'],
        'category' => ['category', 'client category', 'kategori', 'kategori klien'],
    ];

    public function __construct(private MalwareScanner $scanner, private WorkbookReader $reader) {}

    /**
     * @return array<int, array{code: string, name: string, category: string}> keyed by workbook row number
     */
    public function rows(string $path): array
    {
        $this->scanner->scan($path);
        $sheet = $this->reader->inspect($path)[0]['worksheetName'] ?? throw new RuntimeException('invalid_sheet_header');
        $values = $this->reader->read($path, $sheet, 1);
        $header = $values[1] ?? [];
        unset($values[1]);
        $columns = [];
        foreach ($header as $column => $label) {
            $normalized = ImportWorkflow::normalize($label);
            foreach (self::HEADERS as $field => $aliases) {
                if (in_array($normalized, $aliases, true)) {
                    $columns[$field] ??= $column;
                }
            }
        }
        if (! isset($columns['code'], $columns['name'])) {
            throw new RuntimeException('missing_headers');
        }
        $rows = [];
        foreach ($values as $number => $row) {
            $rows[$number] = [
                'code' => trim($row[$columns['code']] ?? ''),
                'name' => trim($row[$columns['name']] ?? ''),
                'category' => isset($columns['category']) ? trim($row[$columns['category']] ?? '') : '',
            ];
        }

        return $rows;
    }

    public function template(): string
    {
        $book = new Spreadsheet;
        $sheet = $book->getActiveSheet()->setTitle('Clients');
        $sheet->fromArray([['Client code', 'Client name', 'Client category'], ['TNB', 'Tenaga Nasional Berhad', 'Utility']]);
        $sheet->getStyle('A1:C1')->getFont()->setBold(true);
        foreach (['A' => 18, 'B' => 40, 'C' => 24] as $column => $width) {
            $sheet->getColumnDimension($column)->setWidth($width);
        }
        ob_start();
        (new Xlsx($book))->save('php://output');
        $book->disconnectWorksheets();

        return (string) ob_get_clean();
    }
}
