<?php

class WorkoutRepository 
{
    private $db;

    public function __construct($mysqli) 
    {
        $this->db = $mysqli;
    }

    public function saveWorkout(array $workoutData): bool 
    {
        $date = $workoutData['date'] ?? date('Y-m-d');
        $type = $workoutData['type'] ?? 'general';
        
        $workoutId = bin2hex(random_bytes(8)); // UUIDs for binary(16)

        // Insert Workout
        $stmt = $this->db->prepare(
            'INSERT INTO Workout (workout_id, date, type) VALUES (UNHEX(?), ?, ?)'
        );
        $stmt->bind_param('sss', $workoutId, $date, $type);

        if (!$stmt->execute()) 
        {
            error_log("Failed to insert workout: " . $stmt->error);
            return false;
        }

        // Insert Exercises and Sets
        foreach ($workoutData['exercises'] ?? [] as $exerciseNum => $exercise) 
        {
            $exerciseId = bin2hex(random_bytes(8));

            $stmt = $this->db->prepare(
                'INSERT INTO Exercise (exercise_id, workout_id, number, name, notes) VALUES (UNHEX(?), UNHEX(?), ?, ?, ?)'
            );
            $exerciseName = $exercise['name'] ?? 'Unknown';
            $exerciseNotes = $exercise['notes'] ?? null;
            $exNum = $exerciseNum + 1;
            
            $stmt->bind_param('sssss', $exerciseId, $workoutId, $exNum, $exerciseName, $exerciseNotes);

            if (!$stmt->execute()) 
            {
                error_log("Failed to insert exercise: " . $stmt->error);
                continue;
            }

            // Insert Sets
            foreach ($exercise['sets'] ?? [] as $setNum => $set) 
            {
                $setId = bin2hex(random_bytes(8));
                $reps = $set['reps'] ?? 0;
                $warmup = $set['warmup'] ? 1 : 0;
                $dropset = $set['dropset'] ? 1 : 0;
                $failure = $set['failure'] ? 1 : 0;

                $stmt = $this->db->prepare(
                    'INSERT INTO Set (set_id, exercise_id, number, reps, warmup, dropset, failure) VALUES (UNHEX(?), UNHEX(?), ?, ?, ?, ?, ?)'
                );
                $sNum = $setNum + 1;
                
                $stmt->bind_param('sssiiis', $setId, $exerciseId, $sNum, $reps, $warmup, $dropset, $failure);
                $stmt->execute();
            }
        }

        return true;
    }
}
?>