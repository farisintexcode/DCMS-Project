<?php

namespace App\Services\Imports;

use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Reader\IReadFilter;
use PhpOffice\PhpSpreadsheet\RichText\RichText;
use PhpOffice\PhpSpreadsheet\Shared\OLE;
use RuntimeException;
use ZipArchive;

/** M04: no formula evaluation, no remote resources, bounded XLS/XLSX only. */
class WorkbookReader
{
    public function inspect(string $path): array
    {
        $format = IOFactory::identify($path, ['Xlsx', 'Xls']);
        if ($format === 'Xlsx') {
            $this->archive($path);
        } else {
            $this->legacyArchive($path);
        }
        $info = IOFactory::createReader($format)->listWorksheetInfo($path);
        if (! $info || count($info) > config('imports.max_sheets')) {
            throw new RuntimeException('sheet_limit');
        }
        foreach ($info as $sheet) {
            if (mb_strlen($sheet['worksheetName']) > 100 || $sheet['totalRows'] > config('imports.max_rows') + 100 || $sheet['totalColumns'] > config('imports.max_columns')) {
                throw new RuntimeException('workbook_limit');
            }
        }

        return $info;
    }

    public function read(string $path, string $sheet, int $header): array
    {
        $info = collect($this->inspect($path))->firstWhere('worksheetName', $sheet);
        if (! $info || $header < 1 || $header > 100 || $header > $info['totalRows']) {
            throw new RuntimeException('invalid_sheet_header');
        }
        if (config('imports.max_rows') < $info['totalRows'] - $header) {
            throw new RuntimeException('row_limit');
        }
        $reader = IOFactory::createReader(IOFactory::identify($path, ['Xlsx', 'Xls']));
        $reader->setLoadSheetsOnly($sheet)->setReadEmptyCells(false);
        $reader->setReadFilter(new class($header, config('imports.max_rows'), config('imports.max_columns')) implements IReadFilter
        {
            public function __construct(private int $header, private int $rows, private int $columns) {}

            public function readCell(string $columnAddress, int $row, string $worksheetName = ''): bool
            {
                return $row >= $this->header && $row <= $this->header + $this->rows && Coordinate::columnIndexFromString($columnAddress) <= $this->columns;
            }
        });
        $book = $reader->load($path);
        try {
            if ($book->hasMacros()) {
                throw new RuntimeException('macros_not_allowed');
            }
            $page = $book->getSheetByName($sheet);
            $result = [];
            for ($row = $header; $row <= $info['totalRows']; $row++) {
                $values = [];
                for ($column = 1; $column <= $info['totalColumns']; $column++) {
                    $cell = $page->getCell([$column, $row]);
                    if ($cell->getDataType() === DataType::TYPE_FORMULA) {
                        throw new RuntimeException('formulas_not_allowed');
                    }
                    if ($cell->getDataType() === DataType::TYPE_ERROR) {
                        throw new RuntimeException('cell_error');
                    }
                    $value = $cell->getValue();
                    if ($value instanceof RichText) {
                        $value = $value->getPlainText();
                    }
                    if (is_numeric($value) && preg_match('/^0+$/', $cell->getStyle()->getNumberFormat()->getFormatCode())) {
                        $value = str_pad((string) $value, strlen($cell->getStyle()->getNumberFormat()->getFormatCode()), '0', STR_PAD_LEFT);
                    }
                    $value = $value === null ? '' : (string) $value;
                    if (mb_strlen($value) > 5000) {
                        throw new RuntimeException('cell_limit');
                    }
                    $values[$column] = $value;
                }
                if ($row === $header || count(array_filter($values, fn ($value) => $value !== '')) > 0) {
                    $result[$row] = $values;
                }
            }

            return $result;
        } finally {
            $book->disconnectWorksheets();
        }
    }

    private function archive(string $path): void
    {
        $zip = new ZipArchive;
        if ($zip->open($path) !== true) {
            throw new RuntimeException('invalid_archive');
        }
        try {
            if ($zip->numFiles > 5000) {
                throw new RuntimeException('archive_limit');
            }
            $expanded = 0;
            for ($index = 0; $index < $zip->numFiles; $index++) {
                $stat = $zip->statIndex($index);
                $name = strtolower($stat['name']);
                $expanded += $stat['size'];
                if ($expanded > config('imports.max_expanded_bytes') || $stat['size'] > config('imports.max_expanded_bytes') || $stat['size'] > max(1048576, $stat['comp_size'] * 100) || str_contains($name, '..') || preg_match('~vbaproject|activex|embeddings|externallinks|\.bin$~', $name)) {
                    throw new RuntimeException('unsafe_archive');
                }
                if (str_ends_with($name, '.rels') && preg_match('/TargetMode\s*=\s*["\x27]External["\x27]/i', $zip->getFromIndex($index))) {
                    throw new RuntimeException('external_links_not_allowed');
                }
            }
        } finally {
            $zip->close();
        }
    }

    private function legacyArchive(string $path): void
    {
        $ole = new OLE;
        try {
            $ole->read($path);
            foreach ($ole->_list as $entry) {
                if (! in_array($entry->Name, ['Root Entry', 'Workbook', 'Book', "\x05SummaryInformation", "\x05DocumentSummaryInformation"], true)) {
                    throw new RuntimeException('embedded_content_not_allowed');
                }
            }
            $bytes = $ole->getDataByName($ole->hasDataByName('Workbook') ? 'Workbook' : 'Book');
            // Reject executable sheet types, encrypted streams and external/DDE link records before loading BIFF.
            for ($offset = 0; $offset + 4 <= strlen($bytes);) {
                $record = unpack('vtype/vlength', substr($bytes, $offset, 4));
                $data = substr($bytes, $offset + 4, $record['length']);
                $externalBook = $record['type'] === 0x01AE && (strlen($data) < 4 || unpack('vcode', substr($data, 2, 2))['code'] !== 0x0401);
                if ($externalBook || in_array($record['type'], [0x002F, 0x0023], true) || ($record['type'] === 0x0085 && strlen($data) > 5 && ord($data[5]) !== 0)) {
                    throw new RuntimeException('unsafe_legacy_workbook');
                }
                $offset += 4 + $record['length'];
            }
        } finally {
            if (is_resource($ole->_file_handle)) {
                fclose($ole->_file_handle);
            }
        }
    }
}
