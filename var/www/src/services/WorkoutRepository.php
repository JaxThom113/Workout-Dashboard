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
        $workoutId = bin2hex(random_bytes(8)); // assign a random 16-character hex ID to this Workout

        // insert a Workout to the database
        $stmt = $this->db->prepare(
            'INSERT INTO Workout (workout_id, date, type) VALUES (UNHEX(?), ?, ?)'
        );
        $stmt->bind_param('sss', $workoutId, $date, $type);
        $stmt->execute();

        foreach ($workoutData['exercises'] ?? [] as $exerciseNum => $exercise) 
        {
            $exerciseId = bin2hex(random_bytes(8)); // assign a random 16-character hex ID to this Exercise
            $exerciseName = $exercise['name'] ?? 'Unknown';
            $exerciseNotes = $exercise['notes'] ?? null;
            $exNum = $exerciseNum + 1;

            // insert an Exercise linked to the Workout
            $stmt = $this->db->prepare(
                'INSERT INTO Exercise (exercise_id, workout_id, number, name, notes) VALUES (UNHEX(?), UNHEX(?), ?, ?, ?)'
            );   
            $stmt->bind_param('sssss', $exerciseId, $workoutId, $exNum, $exerciseName, $exerciseNotes);
            $stmt->execute();

            foreach ($exercise['sets'] ?? [] as $setNum => $set) 
            {
                $setId = bin2hex(random_bytes(8)); // assign a random 16-character hex ID to this Exercise_Set
                $sNum = $setNum + 1;
                $reps = $set['reps'] ?? 0;
                $warmup = $set['warmup'] ? 1 : 0;
                $dropset = $set['dropset'] ? 1 : 0;
                $failure = $set['failure'] ? 1 : 0;

                // insert a Exercise_Set linked to the Exercise
                $stmt = $this->db->prepare(
                    'INSERT INTO Exercise_Set (set_id, exercise_id, number, reps, warmup, dropset, failure) VALUES (UNHEX(?), UNHEX(?), ?, ?, ?, ?, ?)'
                );
                $stmt->bind_param('sssiiis', $setId, $exerciseId, $sNum, $reps, $warmup, $dropset, $failure);
                $stmt->execute();
            }
        }

        return true;
    }
}
?>