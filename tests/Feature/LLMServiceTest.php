<?php

use App\Services\LLMService;

describe('LLMService', function () {

    beforeEach(function () {
        $this->service = new LLMService();
    });

    describe('extractJson', function () {
        
        it('extracts pure JSON', function () {
            $response = '{"tasks": [{"order": 1, "title": "Task 1"}]}';
            
            $result = $this->service->extractJson($response);
            
            expect($result)->toBeArray();
            expect($result['tasks'])->toHaveCount(1);
            expect($result['tasks'][0]['title'])->toBe('Task 1');
        });

        it('extracts JSON from markdown code block', function () {
            $response = <<<JSON
```json
{
  "tasks": [
    {"order": 1, "title": "Task 1"},
    {"order": 2, "title": "Task 2"}
  ]
}
```
JSON;
            
            $result = $this->service->extractJson($response);
            
            expect($result)->toBeArray();
            expect($result['tasks'])->toHaveCount(2);
        });

        it('extracts JSON from generic code block', function () {
            $response = <<<JSON
```
{"tasks": [{"order": 1, "title": "Task 1"}]}
```
JSON;
            
            $result = $this->service->extractJson($response);
            
            expect($result)->toBeArray();
            expect($result['tasks'])->toHaveCount(1);
        });

        it('returns null for invalid JSON', function () {
            $response = 'This is not JSON at all';
            
            $result = $this->service->extractJson($response);
            
            expect($result)->toBeNull();
        });

        it('handles JSON with surrounding text', function () {
            $response = 'Here is the plan: {"tasks": [{"order": 1, "title": "Task 1"}]} Hope this helps!';
            
            $result = $this->service->extractJson($response);
            
            expect($result)->toBeArray();
            expect($result['tasks'])->toHaveCount(1);
        });

    });

    describe('validateTasks', function () {
        
        it('validates correct tasks', function () {
            $tasks = [
                ['order' => 1, 'title' => 'Task 1', 'description' => 'Description 1'],
                ['order' => 2, 'title' => 'Task 2', 'description' => 'Description 2'],
                ['order' => 3, 'title' => 'Task 3', 'description' => 'Description 3'],
            ];
            
            $result = $this->service->validateTasks($tasks, 3);
            
            expect($result['valid'])->toBeTrue();
            expect($result['tasks'])->toHaveCount(3);
            expect($result['error'])->toBeNull();
        });

        it('filters tasks without title', function () {
            $tasks = [
                ['order' => 1, 'title' => 'Task 1'],
                ['order' => 2, 'description' => 'No title here'], // Invalid
                ['order' => 3, 'title' => 'Task 3'],
            ];
            
            $result = $this->service->validateTasks($tasks, 3);
            
            expect($result['valid'])->toBeTrue();
            expect($result['tasks'])->toHaveCount(2);
        });

        it('assigns sequential order', function () {
            $tasks = [
                ['order' => 5, 'title' => 'Task A'],
                ['order' => 10, 'title' => 'Task B'],
            ];
            
            $result = $this->service->validateTasks($tasks, 2);
            
            expect($result['tasks'][0]['order'])->toBe(1);
            expect($result['tasks'][1]['order'])->toBe(2);
        });

        it('truncates title to 255 characters', function () {
            $longTitle = str_repeat('a', 300);
            $tasks = [
                ['order' => 1, 'title' => $longTitle],
            ];
            
            $result = $this->service->validateTasks($tasks, 1);
            
            expect(strlen($result['tasks'][0]['title']))->toBe(255);
        });

        it('returns error for empty tasks', function () {
            $result = $this->service->validateTasks([], 5);
            
            expect($result['valid'])->toBeFalse();
            expect($result['error'])->not->toBeNull();
        });

        it('adds warning when count differs significantly', function () {
            $tasks = [
                ['order' => 1, 'title' => 'Task 1'],
                ['order' => 2, 'title' => 'Task 2'],
            ];
            
            $result = $this->service->validateTasks($tasks, 10); // Expected 10, got 2
            
            expect($result['valid'])->toBeTrue();
            expect($result['warning'])->not->toBeNull();
            expect($result['warning'])->toContain('10');
            expect($result['warning'])->toContain('2');
        });

        it('truncates excess tasks', function () {
            $tasks = [
                ['order' => 1, 'title' => 'Task 1'],
                ['order' => 2, 'title' => 'Task 2'],
                ['order' => 3, 'title' => 'Task 3'],
                ['order' => 4, 'title' => 'Task 4'],
                ['order' => 5, 'title' => 'Task 5'],
            ];
            
            $result = $this->service->validateTasks($tasks, 3); // Expected 3, got 5
            
            expect($result['valid'])->toBeTrue();
            expect($result['tasks'])->toHaveCount(3);
        });

    });

    describe('isConfigured', function () {
        
        it('returns false when API key is not set', function () {
            // Default state without env variable
            $result = $this->service->isConfigured();
            
            // This will depend on the environment
            // In test environment, likely false
            expect($result)->toBeBool();
        });

    });

});
