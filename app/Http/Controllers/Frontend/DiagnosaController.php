<?php

namespace App\Http\Controllers\Frontend;

use App\Http\Controllers\Controller;
use App\Models\BasisPengetahuan;
use App\Models\Diagnosa;
use App\Models\Gejala;
use App\Models\Penyakit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class DiagnosaController extends Controller
{
    public function index()
    {
        $datas = [
            'titlePage' => 'Diagnosis',
            'navLink' => 'diagnosa',
            'dataGejala' => Gejala::all(),
            'gejala' => Gejala::orderBy('kategori', 'asc')->get()->groupBy('kategori'),
        ];
        // dd(Gejala::orderBy('kategori', 'asc')->get()->groupBy('kategori'));
        return view('Frontend.pages.diagnosa', $datas);
    }
    public function index2()
    {
        $GejalaUmum = BasisPengetahuan::with('gejala')->select('tabel_basis_pengetahuan.kode_gejala', DB::raw('COUNT(*) as total'))
            ->leftJoin('tabel_data_gejala', 'tabel_data_gejala.id_gejala', '=', 'tabel_basis_pengetahuan.kode_gejala')
            ->groupBy('tabel_basis_pengetahuan.kode_gejala')
            ->orderByDesc('total')->limit(5)
            ->get();
        // dd($GejalaUmum);
        $datas = [
            'titlePage' => 'Diagnosis',
            'navLink' => 'diagnosa',
            'index' => Gejala::all(),
            'GejalaUmum' => $GejalaUmum,
            // 'gejala' => Gejala::orderBy('kategori', 'asc')->get()->groupBy('kategori'),
        ];
        // dd(Gejala::orderBy('kategori', 'asc')->get()->groupBy('kategori'));
        return view('Frontend.pages.diagnosa2', $datas);
    }
    public function cekDataBerikutnya(Request $request)
    {
        $arrHasilUser = $request->input('resultGejala');
        $penyakit = BasisPengetahuan::whereIn('kode_gejala', $arrHasilUser)
            ->select('kode_penyakit')
            ->distinct()
            ->get();

        $gejalaSelanjutnya = BasisPengetahuan::whereIn('kode_penyakit', $penyakit)
            ->select('kode_gejala', DB::raw('count(*) as jumlah'))
            ->groupBy('kode_gejala')
            ->orderBy('jumlah', 'desc')
            ->get();

        // echo $gejalaSelanjutnya;
        // dd($gejalaSelanjutnya);
        return response()->json([
            'success' => true,
            'message' => 'Data processed successfully',
            'data' => $gejalaSelanjutnya // or any other data you wish to return
        ]);
    }
    public function showdata($data_diagnosa)
    {
        $dataDiagnosa = Diagnosa::find($data_diagnosa)->toArray();
        $diagnosa = json_decode($dataDiagnosa['diagnosa']);
        $kode_penyakit = strstr($diagnosa->nama_penyakit, ' -', true);
        $penyakit = BasisPengetahuan::with('gejala')->where('kode_penyakit', $kode_penyakit)->get();
        // dd($penyakit);

        $dataTampilan = [
            'titlePage' => 'Hasil Diagnosis',
            'navLink' => 'diagnosa',
            'gejalaSebenarnya' => $penyakit,
            'namaPemilik' => $dataDiagnosa['user']['name'],
            'diagnosa' => $diagnosa,
            'solusi' => json_decode($dataDiagnosa['solusi'])
        ];

        return view('Frontend.pages.hasildiagnosa', $dataTampilan);
    }

    public function kalkulator(Request $request)
    {
        if (auth()->user() == False) {
            return redirect()->to('/')->with('error', 'Login Diperlukan Untuk Diagnosis');
        }

        $arrHasilUser = $request->input('resultGejala');

        if ($arrHasilUser == null) {
            return back()->withInput()->with('error', 'Anda belum memilih gejala');
        } else {
            // if (count($arrHasilUser) < Penyakit::count() + 1) {
            //     return back()->withInput()->with('error', 'Minimal gejala yang dipilih adalah ' . (Penyakit::count() + 1) . ' gejala');
            // } else {
            foreach ($arrHasilUser as $key => $value) {
                $dataPenyakit[$key] = BasisPengetahuan::where('kode_gejala', $value)
                    ->select('kode_penyakit')
                    ->get()
                    ->toArray();
                foreach ($dataPenyakit[$key] as $a => $b) {
                    $resultData[$key]['daftar_penyakit'][$a] = $b['kode_penyakit'];
                }
                $dataNilaiDensitas[$key] = Gejala::where('kode_gejala', $value)
                    ->select('nilai_densitas', 'gejala')
                    ->get()
                    ->toArray();
                $dataGejala[$key] = $dataNilaiDensitas[$key][0]['gejala'];
                $resultData[$key]['belief'] = $dataNilaiDensitas[$key][0]['nilai_densitas'];
                $resultData[$key]['plausibility'] = 1 - $dataNilaiDensitas[$key][0]['nilai_densitas'];
            }

            $variabelTampilan = $this->mulaiPerhitungan($resultData);

            foreach ($dataGejala as $key => $value) {
                $variabelTampilan['Gejala_Penyakit'][$key]['kode_gejala'] = $arrHasilUser[$key];
                $variabelTampilan['Gejala_Penyakit'][$key]['nama_gejala'] = $value;
            }
            // dd($variabelTampilan);
            $diagnosaSavedData = [
                'nama_penyakit' => $variabelTampilan['Nama_Penyakit'],
                'nilai_belief' => $variabelTampilan['Nilai_Belief_Penyakit'],
                'persentase_penyakit' => $variabelTampilan['Persentase_Penyakit'],
                'gejala_penyakit' => $variabelTampilan['Gejala_Penyakit']
            ];

            $diagnosa = new Diagnosa();
            $diagnosa->id_user = auth()->user()->id;
            $diagnosa->diagnosa = json_encode($diagnosaSavedData);
            $diagnosa->solusi = json_encode($variabelTampilan['Solusi_Penyakit']);
            $diagnosa->save();
            $idDiagnosa = $diagnosa->id_diagnosa;

            return redirect()->to('diagnosa/' . $idDiagnosa);
            // }
        }
    }

    public function mulaiPerhitungan($dataAcuan)
    {
        $x = 0;
        for ($i = 0; $i < count($dataAcuan); $i++) {
            $hasilKonversi[$i]['data'][0]['array'] = $dataAcuan[$i]['daftar_penyakit'];
            $hasilKonversi[$i]['data'][0]['value'] = $dataAcuan[$i]['belief'];
            $hasilKonversi[$i]['data'][1]['array'] = [];
            $hasilKonversi[$i]['data'][1]['value'] = $dataAcuan[$i]['plausibility'];

            $x++;
        }

        $result = $this->startingPoint(count($hasilKonversi) - 2, $hasilKonversi);

        $arrResult = [];
        foreach ($result['data'] as $key => $value) {
            $arrResult[$key] = $value['value'];
        }

        $indexMaxValue = array_search(max($arrResult), $arrResult);
        $nilaiBelief = round($result['data'][$indexMaxValue]['value'], 3);
        // $nilaiBelief = $result['data'][$indexMaxValue]['value'];
        $persentase = (round($result['data'][$indexMaxValue]['value'], 3) * 100) . " %";
        // $persentase = ($result['data'][$indexMaxValue]['value'] * 100) . " %";
        // dd($persentase);
        $kodePenyakit = $result['data'][$indexMaxValue]['array'][0];
        $dataPenyakit = Penyakit::where('kode_penyakit', $kodePenyakit)
            ->select('nama_penyakit')
            ->get()
            ->toArray()[0];
        $dataSolusi = Penyakit::where('kode_penyakit', $kodePenyakit)
            ->select('solusi')
            ->get()
            ->toArray()[0];
        $jsonData = [
            'Nama_Penyakit' => $kodePenyakit . ' - ' . $dataPenyakit['nama_penyakit'],
            'Nilai_Belief_Penyakit' => $nilaiBelief,
            'Persentase_Penyakit' => $persentase,
            'Solusi_Penyakit' => $dataSolusi,
        ];

        return $jsonData;
    }

    public function startingPoint(int $jumlah, array $myData, $data = [], int $indeks = 0)
    {
        if (count($data) == 0) {
            $hasilAkhir = $this->kalkulatorPerhitungan($myData[$indeks], $myData[$indeks + 1]);
        } else {
            $hasilAkhir = $this->kalkulatorPerhitungan($data, $myData[$indeks + 1]);
        }

        if ($indeks < $jumlah) {
            return $this->startingPoint($jumlah, $myData, $hasilAkhir, $indeks + 1);
        } else {
            return $hasilAkhir;
        }
    }

    public function kalkulatorPerhitungan($array1, $array2)
    {
        $hasilAkhir['data'] = [];

        $hasilSementara = [];
        $z = 0;
        for ($x = 0; $x < count($array1['data']); $x++) {
            for ($y = 0; $y < count($array2['data']); $y++) {
                if (count($array1['data'][$x]['array']) != 0 && count($array2['data'][$y]['array']) != 0) {
                    $hasilSementara[$z]['array'] = json_encode(array_values(array_intersect($array1['data'][$x]['array'], $array2['data'][$y]['array'])));
                    if (count(json_decode($hasilSementara[$z]['array'])) == 0) {
                        $hasilSementara[$z]['status'] = "Himpunan Kosong";
                    }
                } else {
                    $hasilSementara[$z]['array'] = json_encode(array_merge($array1['data'][$x]['array'], $array2['data'][$y]['array']));
                }
                $hasilSementara[$z]['value'] = $array1['data'][$x]['value'] * $array2['data'][$y]['value'];
                $z++;
            }
        }

        $pushArray = [];
        foreach ($hasilSementara as $hasil) {
            array_push($pushArray, $hasil['array']);
        }

        $pushArrayCat = [];
        foreach (array_count_values($pushArray) as $key => $value) {
            array_push($pushArrayCat, $key);
        }

        $tetapan = 0;
        foreach ($hasilSementara as $datahasil) {
            if (isset($datahasil['status']) && $datahasil['status'] == "Himpunan Kosong") {
                $tetapan += $datahasil['value'];
            }
        }

        $tetapan = 1 - $tetapan;

        $finalResult = [];
        for ($y = 0; $y < count($pushArrayCat); $y++) {
            $decode[$y] = json_decode($pushArrayCat[$y]);
            $finalResult[$y]['array'] = $decode[$y];
            $finalResult[$y]['value'] = 0;
            for ($x = 0; $x < count($hasilSementara); $x++) {
                $array[$x] = json_decode($hasilSementara[$x]['array']);
                if ($decode[$y] == $array[$x]) {
                    if (!isset($hasilSementara[$x]['status'])) {
                        $finalResult[$y]['value'] += $hasilSementara[$x]['value'];
                    }
                }
            }
            $finalResult[$y]['value'] = $finalResult[$y]['value'] / $tetapan;
        }

        for ($i = 0; $i < count($finalResult); $i++) {
            $hasilAkhir['data'][$i] = $finalResult[$i];
        }

        return $hasilAkhir;
    }
}
