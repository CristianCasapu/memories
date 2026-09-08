<?php

declare(strict_types=1);

namespace OCA\Memories\Db;

use OCP\AppFramework\Db\Entity;

/**
 * One video made from photos, from the request to the file.
 *
 * @method string      getUid()
 * @method void        setUid(string $uid)
 * @method string      getStatus()
 * @method void        setStatus(string $status)
 * @method string      getTitle()
 * @method void        setTitle(string $title)
 * @method string      getFileIds()
 * @method void        setFileIds(string $fileIds)
 * @method int         getPhotos()
 * @method void        setPhotos(int $photos)
 * @method float       getFps()
 * @method void        setFps(float $fps)
 * @method string      getMusic()
 * @method void        setMusic(string $music)
 * @method string      getMood()
 * @method void        setMood(string $mood)
 * @method int         getProgress()
 * @method void        setProgress(int $progress)
 * @method string      getStep()
 * @method void        setStep(string $step)
 * @method null|int    getResultFileid()
 * @method void        setResultFileid(?int $fileid)
 * @method string      getResultName()
 * @method void        setResultName(string $name)
 * @method string      getResultFolder()
 * @method void        setResultFolder(string $folder)
 * @method null|string getTrack()
 * @method void        setTrack(?string $track)
 * @method null|string getError()
 * @method void        setError(?string $error)
 * @method int         getCreated()
 * @method void        setCreated(int $created)
 * @method int         getStarted()
 * @method void        setStarted(int $started)
 * @method int         getFinished()
 * @method void        setFinished(int $finished)
 */
final class VideoJob extends Entity
{
    public const QUEUED = 'queued';
    public const RUNNING = 'running';
    public const DONE = 'done';
    public const FAILED = 'failed';
    public const CANCELLED = 'cancelled';

    protected string $uid = '';
    protected string $status = self::QUEUED;
    protected string $title = '';
    protected string $fileIds = '[]';
    protected int $photos = 0;
    protected float $fps = 2.0;
    protected string $music = 'auto';
    protected string $mood = '';
    protected int $progress = 0;
    protected string $step = '';
    protected ?int $resultFileid = null;
    protected string $resultName = '';
    protected string $resultFolder = '';
    protected ?string $track = null;
    protected ?string $error = null;
    protected int $created = 0;
    protected int $started = 0;
    protected int $finished = 0;

    public function __construct()
    {
        $this->addType('id', 'integer');
        $this->addType('photos', 'integer');
        $this->addType('fps', 'float');
        $this->addType('progress', 'integer');
        $this->addType('resultFileid', 'integer');
        $this->addType('created', 'integer');
        $this->addType('started', 'integer');
        $this->addType('finished', 'integer');
    }

    /** @return list<int> */
    public function fileIdList(): array
    {
        $ids = json_decode($this->getFileIds(), true);

        return \is_array($ids) ? array_values(array_map('intval', $ids)) : [];
    }

    public function toArray(): array
    {
        $rawTrack = $this->getTrack();
        $track = null !== $rawTrack ? json_decode($rawTrack, true) : null;

        return [
            'id' => $this->getId(),
            'uid' => $this->getUid(),
            'status' => $this->getStatus(),
            'title' => $this->getTitle(),
            'photos' => $this->getPhotos(),
            'fps' => $this->getFps(),
            'music' => $this->getMusic(),
            'mood' => $this->getMood(),
            'progress' => $this->getProgress(),
            'step' => $this->getStep(),
            'result_fileid' => $this->getResultFileid(),
            'result_name' => $this->getResultName(),
            'result_folder' => $this->getResultFolder(),
            'track' => \is_array($track) ? $track : null,
            'error' => $this->getError(),
            'created' => $this->getCreated(),
            'started' => $this->getStarted(),
            'finished' => $this->getFinished(),
        ];
    }
}
