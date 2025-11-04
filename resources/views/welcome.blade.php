<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Aplikasi Analisis Sentimen</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    <style>
        body { background-color: #f8f9fa; }
        .container { margin-top: 50px; }
        .card { margin-bottom: 20px; }
        .nav-tabs .nav-link { cursor: pointer; }
    </style>
</head>
<body>
    <div class="container">
        <h1 class="mb-4 text-center">Aplikasi Analisis Sentimen</h1>

        @if (session('success'))
            <div class="alert alert-success">{{ session('success') }}</div>
        @endif

        @if (session('error'))
            <div class="alert alert-danger" style="white-space: pre-wrap;">{{ session('error') }}</div>
        @endif

        <div class="row">
            <div class="col-md-6">
                <div class="card">
                    <div class="card-header">Langkah 1: Latih Model</div>
                    <div class="card-body">
                        <form action="{{ route('sentiment.train') }}" method="POST" enctype="multipart/form-data">
                            @csrf
                            <div class="mb-3">
                                <label for="training_file" class="form-label">Unggah File Data Latih (.xlsx)</label>
                                <input class="form-control" type="file" id="training_file" name="training_file" required>
                                <small class="form-text text-muted">File harus memiliki kolom 'pertanyaan X' dan 'label X'.</small>
                            </div>
                            <button type="submit" class="btn btn-primary">Latih Model</button>
                        </form>
                    </div>
                </div>
            </div>
            <div class="col-md-6">
                <div class="card">
                    <div class="card-header">Langkah 2: Analisis File Baru</div>
                    <div class="card-body">
                        @if ($modelExists)
                            <form action="{{ route('sentiment.predict') }}" method="POST" enctype="multipart/form-data">
                                @csrf
                                <div class="mb-3">
                                    <label for="analysis_file" class="form-label">Unggah File Data Analisis (.xlsx)</label>
                                    <input class="form-control" type="file" id="analysis_file" name="analysis_file" required>
                                    <small class="form-text text-muted">File harus memiliki kolom 'pertanyaan X'.</small>
                                </div>
                                <button type="submit" class="btn btn-success">Analisis</button>
                            </form>
                        @else
                            <div class="alert alert-warning">Model belum dilatih. Silakan latih model terlebih dahulu.</div>
                        @endif
                    </div>
                </div>
            </div>
        </div>

        @if ($groupedResults)
            <div class="card mt-5">
                <div class="card-header">
                    Langkah 3: Hasil Analisis Sentimen
                </div>
                <div class="card-body">
                    <div class="d-flex justify-content-between align-items-center mb-3">
                        <h4 class="mb-0">Hasil per Pertanyaan</h4>
                        <a href="{{ route('sentiment.download') }}" class="btn btn-primary">Download Semua Hasil (CSV)</a>
                    </div>

                    <ul class="nav nav-tabs" id="questionTabs" role="tablist">
                        @foreach ($groupedResults as $questionName => $results)
                            <li class="nav-item" role="presentation">
                                <button class="nav-link @if ($loop->first) active @endif" id="tab-{{ $loop->index }}" data-bs-toggle="tab" data-bs-target="#pane-{{ $loop->index }}" type="button" role="tab">{{ $questionName }}</button>
                            </li>
                        @endforeach
                    </ul>

                    <div class="tab-content" id="questionTabContent">
                        @foreach ($groupedResults as $questionName => $results)
                            @php $summary = $groupedSummaries[$questionName]; @endphp
                            <div class="tab-pane fade @if ($loop->first) show active @endif" id="pane-{{ $loop->index }}" role="tabpanel">
                                <div class="p-3 border border-top-0">
                                    <a href="{{ route('sentiment.download', ['question' => $questionName]) }}" class="btn btn-secondary btn-sm mb-3">Download Hasil Tab Ini (CSV)</a>
                                    <div class="row mb-4">
                                        <div class="col-md-4">
                                            <div class="card text-white bg-info mb-3">
                                                <div class="card-header">Total Data</div>
                                                <div class="card-body"><h5 class="card-title">{{ $summary['total'] }}</h5></div>
                                            </div>
                                        </div>
                                        <div class="col-md-4">
                                            <div class="card text-white bg-danger mb-3">
                                                <div class="card-header">Sentimen Negatif</div>
                                                <div class="card-body"><h5 class="card-title">{{ $summary['negative'] }} ({{ $summary['negative_percentage'] }}%)</h5></div>
                                            </div>
                                        </div>
                                        <div class="col-md-4">
                                            <div class="card text-white bg-success mb-3">
                                                <div class="card-header">Sentimen Positif</div>
                                                <div class="card-body"><h5 class="card-title">{{ $summary['positive'] }} ({{ $summary['positive_percentage'] }}%)</h5></div>
                                            </div>
                                        </div>
                                    </div>

                                    <div class="row mb-4">
                                        <div class="col-md-6 mx-auto">
                                            <canvas class="sentiment-chart"
                                                    data-positive="{{ $summary['positive'] }}"
                                                    data-negative="{{ $summary['negative'] }}"
                                                    data-title="Distribusi Sentimen: {{ $questionName }}"></canvas>
                                        </div>
                                    </div>

                                    <h5 class="mt-4">Detail Hasil: {{ $questionName }}</h5>
                                    <div class="table-responsive" style="max-height: 400px;">
                                        <table class="table table-striped table-sm">
                                            <thead>
                                                <tr>
                                                    <th>Teks</th>
                                                    <th>Sentimen</th>
                                                </tr>
                                            </thead>
                                            <tbody>
                                                @foreach ($results as $result)
                                                    <tr>
                                                        <td>{{ $result['text'] }}</td>
                                                        <td>{{ $result['sentimen_label'] }}</td>
                                                    </tr>
                                                @endforeach
                                            </tbody>
                                        </table>
                                    </div>
                                </div>
                            </div>
                        @endforeach
                    </div>
                </div>
            </div>
        @endif
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        document.addEventListener('DOMContentLoaded', function () {
            const charts = {};
            const chartCanvases = document.querySelectorAll('.sentiment-chart');

            function createChart(canvas) {
                const positive = canvas.dataset.positive;
                const negative = canvas.dataset.negative;
                const title = canvas.dataset.title;

                const ctx = canvas.getContext('2d');
                charts[canvas.id] = new Chart(ctx, {
                    type: 'pie',
                    data: {
                        labels: ['Negatif', 'Positif'],
                        datasets: [{
                            data: [negative, positive],
                            backgroundColor: ['rgba(220, 53, 69, 0.8)', 'rgba(40, 167, 69, 0.8)'],
                            borderColor: ['rgba(220, 53, 69, 1)', 'rgba(40, 167, 69, 1)'],
                            borderWidth: 1
                        }]
                    },
                    options: {
                        responsive: true,
                        plugins: {
                            legend: { position: 'top' },
                            title: { display: true, text: title }
                        }
                    }
                });
            }

            // Initialize chart for the initially active tab
            const activeCanvas = document.querySelector('.tab-pane.active .sentiment-chart');
            if (activeCanvas) {
                createChart(activeCanvas);
            }

            // Handle tab switching to render charts only when they become visible
            const questionTabTriggers = document.querySelectorAll('#questionTabs button[data-bs-toggle="tab"]');
            questionTabTriggers.forEach(trigger => {
                trigger.addEventListener('shown.bs.tab', function (event) {
                    const targetPane = document.querySelector(event.target.dataset.bsTarget);
                    const canvas = targetPane.querySelector('.sentiment-chart');
                    if (canvas && !charts[canvas.id]) { // Only create chart if it doesn't exist
                        createChart(canvas);
                    }
                });
            });
        });
    </script>
</body>
</html>
