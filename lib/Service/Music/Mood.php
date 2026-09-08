<?php

declare(strict_types=1);

namespace OCA\Memories\Service\Music;

/**
 * The moods a video can have, what they look like (CLIP prompts, compared with the photos)
 * and what music they call for (tags every provider understands, in their own words).
 */
final class Mood
{
    public const AUTO = 'auto';
    public const NONE = 'none';

    /** @var array<string, array{label:string, prompts:list<string>, tags:list<string>, freesound:string, mubert:list<string>}> */
    public const MOODS = [
        'party' => [
            'label' => 'Party',
            'prompts' => ['a photo of a party with people dancing', 'people celebrating at a party at night', 'a wedding party dance floor', 'people toasting with drinks at a celebration'],
            'tags' => ['party', 'dance', 'upbeat', 'electronic'],
            'freesound' => 'upbeat dance music loop',
            'mubert' => ['party', 'dance', 'energetic'],
        ],
        'birthday' => [
            'label' => 'Birthday',
            'prompts' => ['a birthday cake with candles', 'children at a birthday party with balloons', 'someone blowing out birthday candles'],
            'tags' => ['happy', 'celebration', 'fun', 'pop'],
            'freesound' => 'happy birthday cheerful music',
            'mubert' => ['happy', 'celebration', 'pop'],
        ],
        'wedding' => [
            'label' => 'Wedding',
            'prompts' => ['a bride and groom at a wedding', 'a wedding ceremony', 'a bride in a white dress'],
            'tags' => ['romantic', 'wedding', 'piano', 'emotional'],
            'freesound' => 'romantic piano wedding music',
            'mubert' => ['romantic', 'wedding', 'piano'],
        ],
        'sad' => [
            'label' => 'Melancholic',
            'prompts' => ['a sad, melancholic moment', 'a funeral, people mourning', 'a lonely person looking out of a window in the rain', 'an old faded photograph'],
            'tags' => ['sad', 'melancholic', 'piano', 'slow'],
            'freesound' => 'sad piano melancholic',
            'mubert' => ['sad', 'melancholic', 'piano'],
        ],
        'playful' => [
            'label' => 'Playful',
            'prompts' => ['children playing in a playground', 'kids laughing and playing games', 'a toddler playing with toys', 'a puppy or a kitten playing'],
            'tags' => ['playful', 'fun', 'happy', 'ukulele'],
            'freesound' => 'playful happy ukulele',
            'mubert' => ['playful', 'happy', 'kids'],
        ],
        'sunny' => [
            'label' => 'Summer',
            'prompts' => ['a sunny day at the beach', 'people swimming in the sea in summer', 'a bright sunny landscape', 'a swimming pool on a hot summer day'],
            'tags' => ['summer', 'sunny', 'tropical', 'chill'],
            'freesound' => 'summer tropical chill music',
            'mubert' => ['summer', 'tropical', 'chill'],
        ],
        'calm' => [
            'label' => 'Calm',
            'prompts' => ['a calm landscape with mountains and a lake', 'a quiet forest path', 'a peaceful sunset', 'flowers in a garden'],
            'tags' => ['ambient', 'relaxing', 'acoustic', 'calm'],
            'freesound' => 'calm ambient acoustic',
            'mubert' => ['calm', 'ambient', 'relaxing'],
        ],
        'winter' => [
            'label' => 'Winter',
            'prompts' => ['snow and a christmas tree', 'people skiing in the snow', 'christmas decorations and lights', 'a snowy winter landscape'],
            'tags' => ['christmas', 'winter', 'festive', 'bells'],
            'freesound' => 'christmas winter festive music',
            'mubert' => ['christmas', 'winter', 'festive'],
        ],
        'travel' => [
            'label' => 'Travel',
            'prompts' => ['tourists visiting a city with old buildings', 'a famous landmark or monument', 'an airport or a road trip', 'a street in a foreign city'],
            'tags' => ['adventure', 'cinematic', 'travel', 'inspiring'],
            'freesound' => 'cinematic adventure inspiring',
            'mubert' => ['travel', 'cinematic', 'inspiring'],
        ],
        'sport' => [
            'label' => 'Sport',
            'prompts' => ['people playing sports, a football match', 'a runner or a cyclist racing', 'people at the gym or a competition'],
            'tags' => ['energetic', 'rock', 'sport', 'powerful'],
            'freesound' => 'energetic rock sport',
            'mubert' => ['sport', 'energetic', 'rock'],
        ],
        'romantic' => [
            'label' => 'Romantic',
            'prompts' => ['a couple in love kissing', 'a romantic dinner by candlelight', 'a couple holding hands at sunset'],
            'tags' => ['romantic', 'love', 'soft', 'guitar'],
            'freesound' => 'romantic soft guitar',
            'mubert' => ['romantic', 'love', 'soft'],
        ],
        'family' => [
            'label' => 'Family',
            'prompts' => ['a family gathered around a dinner table', 'grandparents with grandchildren', 'a family portrait at home', 'people hugging at a family reunion'],
            'tags' => ['warm', 'folk', 'acoustic', 'happy'],
            'freesound' => 'warm acoustic folk family',
            'mubert' => ['family', 'warm', 'acoustic'],
        ],
    ];

    /** @return list<string> */
    public static function ids(): array
    {
        return array_keys(self::MOODS);
    }

    public static function exists(string $id): bool
    {
        return isset(self::MOODS[$id]);
    }

    public static function label(string $id): string
    {
        return self::MOODS[$id]['label'] ?? $id;
    }
}
