<?php

namespace App\Domain\Grading;

final class AcademicRecordPdf
{
    /**
     * @param  list<string>  $lines
     * @param  list<list<bool>>|null  $qrMatrix
     */
    public function render(array $lines, ?array $qrMatrix = null): string
    {
        $normalized = [];
        foreach ($lines as $line) {
            $ascii = iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $line);
            $clean = preg_replace('/[^\x20-\x7E]/', '', $ascii === false ? '' : $ascii) ?? '';
            foreach (explode("\n", wordwrap($clean, 95, "\n", true)) as $wrapped) {
                $normalized[] = $wrapped;
            }
        }
        $pages = array_chunk(array_slice($normalized, 0, 2400), 48);
        if ($pages === []) {
            $pages = [['Academic record']];
        }
        $objects = [];
        $pageIds = [];
        $objects[1] = '<< /Type /Catalog /Pages 2 0 R >>';
        $fontId = 3;
        $objects[$fontId] = '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica >>';
        $nextId = 4;
        foreach ($pages as $pageIndex => $pageLines) {
            $pageId = $nextId++;
            $contentId = $nextId++;
            $pageIds[] = $pageId;
            $commands = "BT\n/F1 10 Tf\n50 790 Td\n14 TL\n";
            foreach ($pageLines as $line) {
                $escaped = str_replace(['\\', '(', ')'], ['\\\\', '\\(', '\\)'], $line);
                $commands .= '('.$escaped.") Tj\nT*\n";
            }
            $commands .= 'ET';
            if ($pageIndex === 0 && $qrMatrix !== null && $qrMatrix !== []) {
                $commands .= "\nq\n";
                $moduleCount = count($qrMatrix);
                $moduleSize = 2.5;
                $quietZone = 4;
                $qrSize = ($moduleCount + 2 * $quietZone) * $moduleSize;
                $left = 430.0;
                $bottom = 560.0;
                $commands .= sprintf("1 1 1 rg\n%.2f %.2f %.2f %.2f re f\n0 0 0 rg\n", $left, $bottom, $qrSize, $qrSize);
                foreach ($qrMatrix as $rowIndex => $row) {
                    foreach ($row as $columnIndex => $dark) {
                        if (! $dark) {
                            continue;
                        }
                        $x = $left + ($columnIndex + $quietZone) * $moduleSize;
                        $y = $bottom + ($moduleCount - 1 - $rowIndex + $quietZone) * $moduleSize;
                        $commands .= sprintf("%.2f %.2f %.2f %.2f re f\n", $x, $y, $moduleSize, $moduleSize);
                    }
                }
                $commands .= 'Q';
            }
            $objects[$contentId] = '<< /Length '.strlen($commands).">>\nstream\n{$commands}\nendstream";
            $objects[$pageId] = '<< /Type /Page /Parent 2 0 R /MediaBox [0 0 612 842] /Resources << /Font << /F1 '.$fontId.' 0 R >> >> /Contents '.$contentId.' 0 R >>';
        }
        $kids = implode(' ', array_map(static fn (int $id): string => $id.' 0 R', $pageIds));
        $objects[2] = '<< /Type /Pages /Kids ['.$kids.'] /Count '.count($pageIds).' >>';
        ksort($objects);
        $pdf = "%PDF-1.4\n";
        $offsets = [0];
        foreach ($objects as $id => $object) {
            $offsets[$id] = strlen($pdf);
            $pdf .= $id." 0 obj\n{$object}\nendobj\n";
        }
        $xrefOffset = strlen($pdf);
        $size = max(array_keys($objects)) + 1;
        $pdf .= "xref\n0 {$size}\n0000000000 65535 f \n";
        for ($id = 1; $id < $size; $id++) {
            $pdf .= sprintf("%010d 00000 n \n", $offsets[$id] ?? 0);
        }

        return $pdf."trailer\n<< /Size {$size} /Root 1 0 R >>\nstartxref\n{$xrefOffset}\n%%EOF";
    }
}
