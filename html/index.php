<?php

// CURL USERPASS
if (!empty(getenv('bluemix_user_pass'))) {
  $bluemix_user_pass = getenv('bluemix_user_pass');
} elseif (!empty(getenv('bluemix_user_pass_file'))) {
  $file = getenv('bluemix_user_pass_file');
  if (file_exists($file)) {
    $bluemix_user_pass = str_replace("\n","",file_get_contents($file));
  } else {
    exit("Missing file: $file");
  }
} else {
  exit("Missing bluemix user pass - see docker file");
}

// PROCESS FILE
if (!empty($_FILES)) {
    if (count($_FILES) > 1) {
        exit("Only one file supported at a time");
    }

    if (empty($_FILES['file'])) {
        exit("The file parameter must be named 'file'");
    }

    // PROCESS UPLOADED FILE
    $file = $_FILES['file'];
    if ($file['error'] != 0) {
        exit("There was an error parsing this file: " . $file['error']);
    }

    $rate = isset($_POST['rate']) ? intval($_POST['rate']) : '';
    $action = isset($_POST['action']) ? strtoupper($_POST['action']) : 'TRANSCODE';
    $output_format = isset($_POST['format']) ? $_POST['format'] : 'wav';
    $output_name = $file['tmp_name'] . ".$output_format";


    $name = $file['name'];
    $size = $file['size'];
    $suffix = pathinfo($name, PATHINFO_EXTENSION);
    $input_name = $file['tmp_name'] . ".$suffix";
    if (! move_uploaded_file($file['tmp_name'], $input_name)) {
        exit("Unable to verify/move file");
    }

    if ($action == 'TRANSCODE') {
        // CALL FFMPEG
        $command = "ffmpeg" .
            " -i " . escapeshellarg($input_name) .
            (empty($rate) ? "" : " -ar $rate") .
            " -y" .
            " " . escapeshellarg($output_name);

        $result = exec($command . " 2>&1 1> /dev/null", $output, $status);

        if ($status !== 0) {
            // Error occurred:
            cleanUp($input_name);
            cleanUp($output_name);
            exit("Error executing $command - Result: $result / Details: " . print_r($output, true));
        }

        // RETURN OUTPUT
        if (!file_exists($output_name)) {
            cleanUp($input_name);
            exit("Unable to locate output file: $output_name");
        }

        returnFile($output_name);

        cleanUp($input_name);
        cleanUp($output_name);

    } elseif ($action == 'TRANSCRIBE') {

        debug("TRANSCRIBE with language $language");

        // First determine if the current format is acceptable for bluemix servers
        $command = "ffprobe" .
            " -i " . escapeshellarg($input_name) .
            " -v quiet" .
            " -print_format json" .
            " -show_streams";

        debug("COMMAND: $command");


        $raw_result = shell_exec($command);

        $result = json_decode($raw_result,true);
        if (!isset($result['streams']) || count($result['streams']) != 1) {
            cleanUp($input_name);
            exit("Transcribing only supports a single stream - your file contains: " . $raw_result);
        }

        $stream = $result['streams'][0];
        $sample_rate = isset($stream['sample_rate']) ? $stream['sample_rate'] : null;
        $codec_name = isset($stream['codec_name']) ? $stream['codec_name'] : null;

        debug("Sample Rate: $sample_rate");
        debug( "Codec: $codec_name");

        if (!empty($code_name) && $sample_rate >= 16000 && substr($codec_name,0,3) == 'pcm') {
            // We do not need to transcode this file before transcribing it
            $transcribe_file = $input_name;
            debug("No transcoding necessary - proceeding with $transcribe_file");

        } else {
            // We need to transcode the input audio file to wav/16000 first
            $rate = 16000;
            $output_name = $file['tmp_name'] . ".wav";
            $command = "ffmpeg" .
                " -i " . escapeshellarg($input_name) .
                (empty($rate) ? "" : " -ar $rate") .
                " " . escapeshellarg($output_name);
            $result = exec($command . " 2>&1 1> /dev/null", $output, $status);

            debug("Transcoded to $output_name with $command");

            if ($status !== 0) {
                // Error occurred:
                cleanUp($input_name);
                cleanUp($output_name);
                exit("Error during transcoding with $command - Result: $result / Details: " . print_r($output, true));
            }
            if (!file_exists($output_name)) {
                cleanUp($input_name);
                exit("Unable to locate output file: $output_name");
            }
            $transcribe_file = $output_name;
        }

        // TRANSCRIBE FILE
        $language = isset($_POST['language']) ? $_POST['language'] : 'en';
        // Set the model
        switch($language) {
            case "es":
                $model = "es-ES_BroadbandModel";
                break;
            case "zh":
                $model = "zh-CN_BroadbandModel";
                break;
            case "pt":
                $model = "pt-BR_BroadbandModel";
                break;
            default:
                $model = "en-US_BroadbandModel";
        }

        $url = "https://stream.watsonplatform.net/speech-to-text/api/v1/recognize?model=$model";
        debug("Transcribing $transcribe_file with url $url");
        $ch = curl_init();

        curl_setopt_array($ch, array(
            CURLOPT_URL             => $url,
            CURLOPT_USERPWD         => $bluemix_user_pass,
            CURLOPT_HTTPHEADER      => array(
                "content-type: audio/wav"
            ),
            CURLOPT_RETURNTRANSFER  => 1,
            CURLOPT_TIMEOUT         => 60,
            CURLOPT_POST            => 1,
            CURLOPT_POSTFIELDS      => file_get_contents($transcribe_file),
        ));
        $raw_result = curl_exec($ch);
        curl_close($ch);

        header('Content-Type: text/json');
        header('Expires: 0');
        header('Cache-Control: must-revalidate');
        print $raw_result;

        cleanUp($input_name);
        cleanUp($transcribe_file);
    } else {
        cleanUp($input_name);
        exit("Invalid Action: $action - valid actions are TRANSCODE or TRANSCRIBE");
    }

    //POST
    exit;
}


function cleanUp($file) {
    error_log("Cleaning up $file");
    if (file_exists($file)) unlink($file);
}

function returnFile($file) {
    header('Content-Description: File Transfer');
    header('Content-Type: application/octet-stream');
    header('Content-Disposition: attachment; filename="' . basename($file) . '"');
    header('Expires: 0');
    header('Cache-Control: must-revalidate');
    header('Pragma: public');
    header('Content-Length: ' . filesize($file));
    readfile($file);
}

function debug($string) {
    //print "<pre>" . $string . "</pre>";
}


?>
<html>
<pre>
    This web service runs FFMPEG to convert a single input file into a single output file.

    file:   The upload file contents with a valid file suffix to indicate the format
    action: TRANSCODE (default) or TRANSCRIBE

    For Transcoding:
        format: The output file format (wav/mp3/etc...)
        rate:   (optional) override the encoding rate in Hz (e.g. 16000)

    For Transcribing:
        language: en (default), es (spanish), zh (chinese), pt (portuguese)

    Example Request:
        POST:
            file => "audio_1.wav"
            format => 'mp3'
            rate => '48000'

        Will return a streamed MP3 file or error message


        POST:
            file => "audio_1.amr"
            language => 'es'

        Will return a json object for the translation of the audio, such as:
        {
            "results": [
                {
                    "alternatives": [
                        {
                            "confidence": 0.28,
                            "transcript": "flat out a few birds "
                        }
                    ],
                    "final": true
                }
            ],
            "result_index": 0
        }

        Since the transcription service only accepts 16kHz or higher samples, in the example above, the 8 Khz amr
        file will be transiently transcoded to a 16kHz wav file and then sent off for transcription.  This can take
        30 seconds or longer.
</pre>
</html>
