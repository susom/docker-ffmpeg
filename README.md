# docker-ffmpeg
a ffmpeg web service

This is a container that does two things:

1) It wraps ffmpeg as a web service so you can post audio and get back a compressed version of the audio in a variety of formats
2) It integrates with IBM Bluemix Watson to provide audio to text capability
	
	https://www.ibm.com/watson/developercloud/doc/speech-to-text/index.html
	


# Building Container
```
docker build -t ffmpeg:latest .
```

# Running Container
```
docker run -d --name 'ffmpeg' \
-e "bluemix_user_pass=xxx:yyy" \
-p 1080:80 \
--restart 'unless-stopped' \
ffmpeg-web-service
```

where xxx:yyy equals the service credentials provided from your watson service.  You must first create a watson service with the speech-to-text engine.
From here you should be able to provision credentials like the following:
```
{
  "url": "https://stream.watsonplatform.net/speech-to-text/api",
  "username": "xxx",
  "password": "yyy"
}
```

# Web Service
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
```json
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
```

Since the transcription service only accepts 16kHz or higher samples, in the example above, the 8 Khz amr
file will be transiently transcoded to a 16kHz wav file and then sent off for transcription.  This can take
30 seconds or longer.
