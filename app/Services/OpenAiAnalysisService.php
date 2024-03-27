<?php


namespace App\Services;

use App\Models\OpenAiLock;
use App\Models\OpenAiMessageTrack;
use App\Models\OpenAiThread;
use OpenAI;

class OpenAiAnalysisService
{
    protected $threadId;
    protected  $client;
    protected  $assId;
    protected $kill = false;

    public function __construct()
    {
    }

    function initialise(string $from): bool
    {
        try {
            $openAiKey = config('app.openAiKey');
            $this->client = OpenAI::client($openAiKey);
            $query = OpenAiThread::where('from', $from);
            if ($query->exists()) {
                $this->threadId = $query->first()->threadId;
            } else {
                $response = $this->client->threads()->create([]);
                $this->threadId = $response->id;
                OpenAiThread::create([
                    'from' => $from,
                    'threadId' => $this->threadId
                ]);
            }
            return true;
        } catch (\Throwable $th) {
            report($th);
            return false;
        }
    }

    function queryDetection($message, $asstID = null): string
    {
        if ($this->kill) {
            return "Killed";
        }
        $toSend = $this->createAndRun($message, $asstID);
        return explode("-", $toSend)[0];
    }
    function getThreadId(): string
    {
        return $this->threadId;
    }

    function createAndRun($message, $assId = null)
    {
        if ($this->kill) {
            return "Killed";
        }
        if ($assId) {
            $this->assId = $assId;
        }
        $lock = OpenAiLock::where('threadId', $this->threadId);
        if ($lock->exists()) {
            OpenAiMessageTrack::create(
                [
                    'threadId' => $this->threadId,
                    'message' => $message
                ]
            );
        } else {
            return $this->createMessages([
                'role' => 'user',
                'content' => $message,
            ]);
        }
    }
    function getAssistantResponse()
    {
        if ($this->kill) {
            return "Killed";
        }
        $response = $this->client->threads()->messages()->list($this->threadId, [
            'limit' => 1,
        ]);
        $result = $response->toArray();
        return $result['data'][0]['content'][0]['text']['value'];
    }
    function createRun()
    {
        if ($this->kill) {
            return "Killed";
        }
        $run =  $this->client->threads()->runs()->create(
            threadId: $this->threadId,
            parameters: [
                'assistant_id' => $this->assId ?? config('app.assistantId'),
            ],
        );
        $this->runRetrievePolling($run->id);
        return $this->getAssistantResponse();
    }
    function runRetrievePolling($runId)
    {
        if ($this->kill) {
            return "Killed";
        }
        OpenAiLock::updateOrCreate(['threadId' => $this->threadId]);
        while (true) {
            sleep(.5);
            $response = $this->client->threads()->runs()->retrieve(
                threadId: $this->threadId,
                runId: $runId,
            );
            if ($response->status == 'completed') break;
        }

        OpenAiLock::where('threadId', $this->threadId)->delete();
        $this->checkMessageTrack();
    }
    function checkMessageTrack()
    {
        if ($this->kill) {
            return "Killed";
        }
        $messages = OpenAiMessageTrack::where('threadId', $this->threadId)->get();
        if ($messages->count()) {
            $mappedMessages = $messages->map(function ($mapMessage) {
                return [
                    'role' => 'user',
                    'content' => $mapMessage->message
                ];
            });
            OpenAiMessageTrack::destroy($messages->pluck('id'));
            return $this->createMessages($mappedMessages);
        }
    }
    function createMessages(array $messages)
    {
        if ($this->kill) {
            return "Killed";
        }
        $response = $this->client->threads()->messages()->create($this->threadId, $messages);
        return $this->createRun();
    }
    function tryToRetrieve($runId)
    {
    }
}
