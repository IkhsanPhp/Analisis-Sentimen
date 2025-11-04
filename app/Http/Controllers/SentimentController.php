<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Response;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\Log;

class SentimentController extends Controller
{
    protected $modelPath;
    protected $flaskApiUrl;

    public function __construct()
    {
        $this->modelPath = storage_path('app/ml/svm_model.pkl');
        $this->flaskApiUrl = 'http://127.0.0.1:5001';
    }

    public function index(Request $request)
    {
        $modelExists = File::exists($this->modelPath);
        $analysisResults = session('analysis_results');

        $groupedResults = null;
        $groupedSummaries = null;

        if ($analysisResults) {
            session()->reflash();
            $questionMapping = [
                'pertanyaan 1' => 'kinerja',
                'pertanyaan 2' => 'pendidikan',
                'pertanyaan 3' => 'kesehatan',
                'pertanyaan 4' => 'pengangguran',
                'pertanyaan 5' => 'kemiskinan',
                'pertanyaan 6' => 'ikm',
                'pertanyaan 7' => 'wisata',
                'pertanyaan 8' => 'pertanian',
                'pertanyaan 9' => 'pangan',
                'pertanyaan 10' => 'infra',
                'pertanyaan 11' => 'konektivitas',
                'pertanyaan 12' => 'lingkungan',
                'pertanyaan 13' => 'keuangan',
                'pertanyaan 14' => 'layanan publik',
            ];

            $collection = collect($analysisResults);
            $groupedResults = $collection->groupBy('source_question')->mapWithKeys(function ($group, $key) use ($questionMapping) {
                return [($questionMapping[$key] ?? $key) => $group];
            });

            $groupedSummaries = $groupedResults->map(function ($group) {
                $total = $group->count();
                $positive = $group->where('sentimen_label', 'Positif')->count();
                $negative = $group->where('sentimen_label', 'Negatif')->count();

                return [
                    'total' => $total,
                    'positive' => $positive,
                    'negative' => $negative,
                    'positive_percentage' => $total > 0 ? round(($positive / $total) * 100, 2) : 0,
                    'negative_percentage' => $total > 0 ? round(($negative / $total) * 100, 2) : 0,
                ];
            });
        }

        return view('welcome', compact('modelExists', 'groupedResults', 'groupedSummaries'));
    }

    public function train(Request $request)
    {
        $request->validate([
            'training_file' => 'required|file|mimes:xlsx,xls',
        ]);

        $file = $request->file('training_file');

        try {
            $fileContent = base64_encode(File::get($file->getRealPath()));

            $response = Http::timeout(300)->post($this->flaskApiUrl . '/train', [
                'file_content' => $fileContent,
            ]);

            if ($response->failed()) {
                $errorMessage = $response->json('error') ?? $response->reason();
                return redirect()->route('sentiment.index')->with('error', 'Gagal melatih model: ' . $errorMessage);
            }

            return redirect()->route('sentiment.index')->with('success', 'Model berhasil dilatih!');

        } catch (\Illuminate\Http\Client\ConnectionException $e) {
            return redirect()->route('sentiment.index')->with('error', 'Gagal terhubung ke server API Python. Pastikan server Flask (app.py) sudah berjalan.');
        } catch (\Exception $e) {
            return redirect()->route('sentiment.index')->with('error', 'Terjadi kesalahan: ' . $e->getMessage());
        }
    }

    public function predict(Request $request)
    {
        $request->validate([
            'analysis_file' => 'required|file|mimes:xlsx,xls',
        ]);

        if (!File::exists($this->modelPath)) {
            return redirect()->route('sentiment.index')->with('error', 'Model belum dilatih. Silakan latih model terlebih dahulu.');
        }

        $file = $request->file('analysis_file');

        try {
            $fileContent = base64_encode(File::get($file->getRealPath()));

            $response = Http::timeout(300)->post($this->flaskApiUrl . '/predict', [
                'file_content' => $fileContent,
            ]);

            if ($response->failed()) {
                $errorMessage = $response->json('error') ?? $response->reason();
                return redirect()->route('sentiment.index')->with('error', 'Gagal melakukan analisis: ' . $errorMessage);
            }

            $results = $response->json();

            return redirect()->route('sentiment.index')->with('analysis_results', $results)->with('success', 'Analisis berhasil dilakukan!');

        } catch (\Illuminate\Http\Client\ConnectionException $e) {
            return redirect()->route('sentiment.index')->with('error', 'Gagal terhubung ke server API Python. Pastikan server Flask (app.py) sudah berjalan.');
        } catch (\Exception $e) {
            return redirect()->route('sentiment.index')->with('error', 'Terjadi kesalahan: ' . $e->getMessage());
        }
    }

    public function downloadResults(Request $request)
    {
        session()->reflash();
        $analysisResults = session('analysis_results');

        if (!$analysisResults) {
            return redirect()->route('sentiment.index')->with('error', 'Tidak ada hasil analisis untuk diunduh.');
        }

        $questionMapping = [
            'pertanyaan 1' => 'kinerja',
            'pertanyaan 2' => 'pendidikan',
            'pertanyaan 3' => 'kesehatan',
            'pertanyaan 4' => 'pengangguran',
            'pertanyaan 5' => 'kemiskinan',
            'pertanyaan 6' => 'ikm',
            'pertanyaan 7' => 'wisata',
            'pertanyaan 8' => 'pertanian',
            'pertanyaan 9' => 'pangan',
            'pertanyaan 10' => 'infra',
            'pertanyaan 11' => 'konektivitas',
            'pertanyaan 12' => 'lingkungan',
            'pertanyaan 13' => 'keuangan',
            'pertanyaan 14' => 'layanan publik',
        ];

        $questionToDownload = $request->input('question');

        if ($questionToDownload) {
            $originalQuestion = array_search($questionToDownload, $questionMapping);
            if ($originalQuestion) {
                $analysisResults = array_filter($analysisResults, function ($row) use ($originalQuestion) {
                    return isset($row['source_question']) && $row['source_question'] === $originalQuestion;
                });
            }
            $csvFileName = 'analisis_sentimen_isu_' . Str::slug($questionToDownload, '_') . '.csv';
        } else {
            $csvFileName = 'analisis_keseluruhan_sentimen'.'.csv';
        }

        $headers = [
            'Content-Type' => 'text/csv',
            'Content-Disposition' => 'attachment; filename="' . $csvFileName . '"',
        ];

        $callback = function() use ($analysisResults) {
            $file = fopen('php://output', 'w');
            fputcsv($file, ['text', 'sentimen_label', 'source_question']);

            foreach ($analysisResults as $row) {
                fputcsv($file, [$row['text'], $row['sentimen_label'], $row['source_question'] ?? 'N/A']);
            }
            fclose($file);
        };

        return Response::stream($callback, 200, $headers);
    }
}
