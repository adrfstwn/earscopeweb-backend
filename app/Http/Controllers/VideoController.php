<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\Video;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Illuminate\Http\File;
use Illuminate\Support\Facades\Log;

class VideoController extends Controller
{
    /**
     * @OA\Post(
     *     path="/v1/videos",
     *     summary="Upload a pair of videos (raw and processed)",
     *     tags={"Videos"},
     *     security={{"bearerAuth":{}}},
     *     @OA\RequestBody(
     *         required=true,
     *         description="Upload raw and processed videos",
     *         @OA\MediaType(
     *             mediaType="multipart/form-data",
     *             @OA\Schema(
     *                 required={"raw_video", "processed_video"},
     *                 @OA\Property(
     *                     property="raw_video",
     *                     type="string",
     *                     format="binary",
     *                     description="Raw video file"
     *                 ),
     *                 @OA\Property(
     *                     property="processed_video",
     *                     type="string",
     *                     format="binary",
     *                     description="Processed video file with bounding box"
     *                 )
     *             )
     *         )
     *     ),
     *     @OA\Response(
     *         response=201,
     *         description="Videos uploaded successfully",
     *         @OA\JsonContent(
     *             type="object",
     *             @OA\Property(property="message", type="string"),
     *             @OA\Property(property="data", type="object",
     *                 @OA\Property(property="id", type="integer"),
     *                 @OA\Property(property="raw_video_path", type="string"),
     *                 @OA\Property(property="processed_video_path", type="string"),
     *                 @OA\Property(property="status", type="string")
     *             )
     *         )
     *     ),
     *     @OA\Response(response=422, description="Validation error")
     * )
     */
    public function store(Request $request)
    {
        $request->validate([
            'raw_video' => 'required|file|mimetypes:video/mp4,video/quicktime',
            'processed_video' => 'required|file|mimetypes:video/mp4,video/quicktime',
        ]);

        $timestamp = now()->format('Ymd_His');
        $folderPath = "videos/{$timestamp}";
        $tempPath = storage_path('app/temp');

        // Pastikan folder temp ada
        if (!file_exists($tempPath)) {
            mkdir($tempPath, 0777, true);
        }

        // Simpan video mentah sementara sebelum konversi
        $rawVideoFilename = "raw_{$timestamp}.mp4";
        $rawTempPath = "{$tempPath}/{$rawVideoFilename}";
        $request->file('raw_video')->move($tempPath, $rawVideoFilename);

        // Simpan video dengan bounding box sementara sebelum konversi
        $processedVideoFilename = "bb_{$timestamp}.mp4";
        $processedTempPath = "{$tempPath}/{$processedVideoFilename}";
        $request->file('processed_video')->move($tempPath, $processedVideoFilename);

        // Path hasil konversi
        $convertedRawVideoFilename = "raw_{$timestamp}_h264.mp4";
        $convertedRawVideoPath = "{$tempPath}/{$convertedRawVideoFilename}";

        $convertedProcessedVideoFilename = "bb_{$timestamp}_h264.mp4";
        $convertedProcessedVideoPath = "{$tempPath}/{$convertedProcessedVideoFilename}";

        // Konversi ke H.264
        $this->convertToH264($rawTempPath, $convertedRawVideoPath);
        $this->convertToH264($processedTempPath, $convertedProcessedVideoPath);

        // Pastikan hasil konversi ada sebelum menyimpan ke storage
        if (!file_exists($convertedRawVideoPath) || !file_exists($convertedProcessedVideoPath)) {
            throw new \Exception("Converted video files not found.");
        }

        // Simpan hasil konversi ke storage Laravel
        $storedRawVideoPath = Storage::disk('private')->putFileAs($folderPath, new File($convertedRawVideoPath), $convertedRawVideoFilename);
        $storedProcessedVideoPath = Storage::disk('private')->putFileAs($folderPath, new File($convertedProcessedVideoPath), $convertedProcessedVideoFilename);

        // Hapus file sementara
        unlink($rawTempPath);
        unlink($processedTempPath);
        unlink($convertedRawVideoPath);
        unlink($convertedProcessedVideoPath);

        // Simpan data ke database
        $video = Video::create([
            'raw_video_path' => $storedRawVideoPath,
            'processed_video_path' => $storedProcessedVideoPath,
            'status' => 'unassigned',
        ]);

        return response()->json([
            'message' => 'Videos uploaded and converted successfully',
            'data' => $video,
        ], 201);
    }
    /**
     * @OA\Post(
     *     path="/v1/videos/{videoId}/assign/{userId}",
     *     summary="Assign a video to a user",
     *     tags={"Videos"},
     *     security={{"bearerAuth":{}}},
     *     @OA\Parameter(name="videoId", in="path", required=true, @OA\Schema(type="integer")),
     *     @OA\Parameter(name="userId", in="path", required=true, @OA\Schema(type="integer")),
     *     @OA\Response(response=200, description="Video assigned successfully"),
     *     @OA\Response(response=404, description="Video or User not found")
     * )
     */
    public function assignToUser($videoId, $userId)
    {
        $video = Video::findOrFail($videoId);
        $video->update([
            'user_id' => $userId,
            'status' => 'assigned',
        ]);

        return response()->json([
            'message' => 'Video assigned to user successfully',
            'data' => $video,
        ]);
    }

    /**
     * @OA\Patch(
     *     path="/v1/videos/{videoId}",
     *     summary="Unassign a video",
     *     tags={"Videos"},
     *     security={{"bearerAuth":{}}},
     *     @OA\Parameter(name="videoId", in="path", required=true, @OA\Schema(type="integer")),
     *     @OA\Response(response=200, description="Video unassigned successfully"),
     *     @OA\Response(response=404, description="Video not found")
     * )
     */
    public function updateStatusVideo($videoId)
    {
        // Temukan video berdasarkan ID
        $video = Video::findOrFail($videoId);

        // Update status dan hapus user_id
        $video->update([
            'status' => 'unassigned',
            'user_id' => null,
        ]);

        return response()->json([
            'message' => 'Video unassigned successfully',
            'data' => $video,
        ]);
    }

    /**
     * @OA\Get(
     *     path="/v1/videos",
     *     summary="Get all videos",
     *     tags={"Videos"},
     *     security={{"bearerAuth":{}}},
     *     @OA\Response(
     *         response=200,
     *         description="List of all videos",
     *         @OA\JsonContent(
     *             type="array",
     *             @OA\Items(
     *                 type="object",
     *                 @OA\Property(property="id", type="integer"),
     *                 @OA\Property(property="raw_video_url", type="string"),
     *                 @OA\Property(property="processed_video_url", type="string"),
     *                 @OA\Property(property="status", type="string"),
     *                 @OA\Property(property="user", type="object",
     *                     @OA\Property(property="id", type="integer"),
     *                     @OA\Property(property="name", type="string")
     *                 )
     *             )
     *         )
     *     ),
     *     @OA\Response(response=401, description="Unauthorized"),
     *     @OA\Response(response=403, description="Forbidden")
     * )
     */
    public function showAllVideos()
    {
        $videos = Video::with('user')->get();

        $videos->transform(function ($video) {
            // $video->raw_video_url = Storage::url($video->raw_video_path);
            // $video->processed_video_url = Storage::url($video->processed_video_path);
            $video->raw_video_stream_url = url("/v1/videos/stream/" . basename($video->raw_video_path));
            $video->processed_video_stream_url = url("/v1/videos/stream/" . basename($video->processed_video_path));
            return $video;
        });

        return response()->json($videos);
    }



    /**
     * @OA\Get(
     *     path="/v1/videos/{videoId}",
     *     summary="Get video details by ID",
     *     tags={"Videos"},
     *     security={{"bearerAuth":{}}},
     *     @OA\Parameter(name="videoId", in="path", required=true, @OA\Schema(type="integer")),
     *     @OA\Response(
     *         response=200,
     *         description="Video details",
     *         @OA\JsonContent(
     *             type="object",
     *             @OA\Property(property="id", type="integer"),
     *             @OA\Property(property="raw_video_url", type="string"),
     *             @OA\Property(property="processed_video_url", type="string"),
     *             @OA\Property(property="status", type="string"),
     *             @OA\Property(property="user", type="object",
     *                 @OA\Property(property="id", type="integer"),
     *                 @OA\Property(property="name", type="string")
     *             )
     *         )
     *     ),
     *     @OA\Response(response=404, description="Video not found"),
     *     @OA\Response(response=401, description="Unauthorized")
     * )
     */
    public function showById($videoId)
    {
        $video = Video::with('user')->findOrFail($videoId);

        // $video->raw_video_url = Storage::url($video->raw_video_path);
        // $video->processed_video_url = Storage::url($video->processed_video_path);
        $video->raw_video_stream_url = url("/v1/videos/stream/" . basename($video->raw_video_path));
        $video->processed_video_stream_url = url("/v1/videos/stream/" . basename($video->processed_video_path));

        return response()->json($video);
    }


    /**
     * @OA\Get(
     *     path="/v1/videos/pasien",
     *     summary="Get videos assigned to the logged-in patient",
     *     tags={"Videos"},
     *     security={{"bearerAuth":{}}},
     *     @OA\Response(
     *         response=200,
     *         description="List of videos assigned to the patient",
     *         @OA\JsonContent(
     *             type="array",
     *             @OA\Items(
     *                 type="object",
     *                 @OA\Property(property="id", type="integer"),
     *                 @OA\Property(property="raw_video_url", type="string"),
     *                 @OA\Property(property="processed_video_url", type="string"),
     *                 @OA\Property(property="status", type="string")
     *             )
     *         )
     *     ),
     *     @OA\Response(response=401, description="Unauthorized"),
     *     @OA\Response(response=403, description="Forbidden")
     * )
     */
    public function showVideosByPasien()
    {
        $user = auth()->user();

        // Pastikan hanya user dengan role "pasien" yang bisa mengakses endpoint ini
        if (!$user->hasRole('pasien')) {
            return response()->json(['message' => 'Forbidden: Only patients can access this endpoint'], 403);
        }

        // Ambil video yang di-assign ke pasien ini (berdasarkan user_id)
        $videos = Video::where('user_id', $user->id)->get();

        // Tambahkan URL lengkap untuk setiap video
        $videos->transform(function ($video) {
            $video->raw_video_url = Storage::url($video->raw_video_path);
            $video->processed_video_url = Storage::url($video->processed_video_path);
            return $video;
        });

        return response()->json($videos);
    }

    /**
     * Convert video to H.264 using FFmpeg
     */
    private function convertToH264($inputPath, $outputPath)
    {
        // Pastikan direktori tujuan ada
        $outputDir = dirname($outputPath);
        if (!file_exists($outputDir)) {
            mkdir($outputDir, 0777, true);
        }

        // Perintah FFmpeg
        $command = "ffmpeg -i \"{$inputPath}\" -c:v libx264 -preset fast -crf 23 -c:a aac -b:a 128k \"{$outputPath}\" 2>&1";
        exec($command, $output, $returnCode);

        // Logging hasil eksekusi
        \Log::info('FFmpeg output', ['output' => $output]);

        if ($returnCode !== 0 || !file_exists($outputPath)) {
            \Log::error('FFmpeg conversion failed', ['command' => $command, 'output' => $output]);
            throw new \Exception("FFmpeg conversion failed. See logs for details.");
        }
    }

    /**
     * @OA\Get(
     *     path="/v1/videos/stream/{filename}",
     *     summary="Stream a video file",
     *     tags={"Videos"},
     *     security={{"bearerAuth":{}}},
     *     @OA\Parameter(
     *         name="filename",
     *         in="path",
     *         required=true,
     *         description="The name of the video file to stream",
     *         @OA\Schema(type="string")
     *     ),
     *@OA\Response(
     *response=200,
     *description="Video streaming successfully",
     *@OA\Header(
     *    header="Content-Type",
     *    description="MIME type of the video",
     *    @OA\Schema(type="string", example="video/mp4")
     *),
     *@OA\Header(
     *   header="Accept-Ranges",
     *   description="bytes range support",
     *   @OA\Schema(type="string", example="bytes")
     *)
     *),
     *     @OA\Response(
     *         response=404,
     *         description="Video not found"
     *     )
     * )
     */

    public function streamVideo($filename)
    {
        Log::info("Streaming request received for: " . $filename);

        // Cari video berdasarkan path di database
        $video = Video::where('raw_video_path', 'like', "%{$filename}")
            ->orWhere('processed_video_path', 'like', "%{$filename}")
            ->first();

        if (!$video) {
            Log::error("Video not found in database: videos/" . $filename);
            return response()->json(['message' => 'Video not found in database'], 404);
        }

        Log::info("Video found in database: " . json_encode($video));

        // Tentukan path file yang benar
        $filePath = null;
        if (str_ends_with($video->raw_video_path, $filename)) {
            $filePath = Storage::disk('private')->path($video->raw_video_path);
        } elseif (str_ends_with($video->processed_video_path, $filename)) {
            $filePath = Storage::disk('private')->path($video->processed_video_path);
        }

        if (!$filePath || !file_exists($filePath)) {
            Log::error("File not found on storage: " . $filePath);
            return response()->json(['message' => 'File not found'], 404);
        }

        Log::info("Streaming file path: " . $filePath);

        return response()->stream(function () use ($filePath) {
            $stream = fopen($filePath, 'rb');
            fpassthru($stream);
            fclose($stream);
        }, 200, [
            'Content-Type' => 'video/mp4',
            'Accept-Ranges' => 'bytes',
            'Content-Length' => filesize($filePath),
        ]);
    }


}