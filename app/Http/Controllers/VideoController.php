<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\Video;
use Illuminate\Support\Facades\Storage;
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
        $user = auth()->user();
        $videos = collect();

        if ($user->hasRole('dokter')) {
            $videos = Video::with('user')->get();
        } elseif ($user->hasRole('pasien')) {
            $videos = Video::where('user_id', $user->id)->with('user')->get();
        } else {
            return response()->json(['message' => 'Unauthorized'], 403);
        }

        $videos->transform(function ($video) {
            $video->raw_video_stream_url = url("/v1/videos/stream/" . basename($video->raw_video_path));
            $video->processed_video_stream_url = url("/v1/videos/stream/" . basename($video->processed_video_path));
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

    // public function streamVideo($filename)
    // {
    //     Log::info("Streaming request received for: " . $filename);

    //     // Cari video berdasarkan path di database
    //     $video = Video::where('raw_video_path', 'like', "%{$filename}")
    //         ->orWhere('processed_video_path', 'like', "%{$filename}")
    //         ->first();

    //     if (!$video) {
    //         Log::error("Video not found in database: videos/" . $filename);
    //         return response()->json(['message' => 'Video not found in database'], 404);
    //     }

    //     Log::info("Video found in database: " . json_encode($video));

    //     // Tentukan path file yang benar
    //     $filePath = null;
    //     if (str_ends_with($video->raw_video_path, $filename)) {
    //         $filePath = Storage::disk('private')->path($video->raw_video_path);
    //     } elseif (str_ends_with($video->processed_video_path, $filename)) {
    //         $filePath = Storage::disk('private')->path($video->processed_video_path);
    //     }

    //     if (!$filePath || !file_exists($filePath)) {
    //         Log::error("File not found on storage: " . $filePath);
    //         return response()->json(['message' => 'File not found'], 404);
    //     }

    //     Log::info("Streaming file path: " . $filePath);

    //     return response()->stream(function () use ($filePath) {
    //         $stream = fopen($filePath, 'rb');
    //         fpassthru($stream);
    //         fclose($stream);
    //     }, 200, [
    //         'Content-Type' => 'video/mp4',
    //         'Accept-Ranges' => 'bytes',
    //         'Content-Length' => filesize($filePath),
    //     ]);
    // }

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

        // Dapatkan informasi file
        $fileSize = filesize($filePath);
        $fileExtension = pathinfo($filePath, PATHINFO_EXTENSION);
        $contentType = $this->getMimeType($fileExtension);

        // Handle HTTP Range Requests
        $headers = [
            'Content-Type' => $contentType,
            'Accept-Ranges' => 'bytes',
            'Content-Length' => $fileSize,
        ];

        if (isset($_SERVER['HTTP_RANGE'])) {
            // Parse range header
            $range = $_SERVER['HTTP_RANGE'];
            preg_match('/bytes=(\d+)-(\d*)/', $range, $matches);
            $start = intval($matches[1]);
            $end = isset($matches[2]) && $matches[2] !== '' ? intval($matches[2]) : $fileSize - 1;

            if ($start > $end || $start >= $fileSize) {
                return response('', 416)->header('Content-Range', "bytes */$fileSize");
            }

            $length = $end - $start + 1;
            $headers['Content-Range'] = "bytes $start-$end/$fileSize";
            $headers['Content-Length'] = $length;
            $status = 206; // Partial Content

            return response()->stream(function () use ($filePath, $start, $length) {
                $stream = fopen($filePath, 'rb');
                fseek($stream, $start);
                echo fread($stream, $length);
                fclose($stream);
            }, $status, $headers);
        }

        // Jika tidak ada range request, kirim file secara keseluruhan
        return response()->stream(function () use ($filePath) {
            $stream = fopen($filePath, 'rb');
            fpassthru($stream);
            fclose($stream);
        }, 200, $headers);
    }

    private function getMimeType($extension)
    {
        $mimeTypes = [
            'mp4' => 'video/mp4',
            'webm' => 'video/webm',
            'ogg' => 'video/ogg',
            'mkv' => 'video/x-matroska',
        ];

        return $mimeTypes[$extension] ?? 'application/octet-stream';
    }
}