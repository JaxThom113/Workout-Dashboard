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
        $workoutId = bin2hex(random_bytes(16));

        try
        {
            $this->db->begin_transaction();

            $stmt = $this->db->prepare(
                'INSERT INTO Workout (workout_id, date, type) VALUES (UNHEX(?), ?, ?)'
            );
            if (!$stmt)
                throw new RuntimeException('Failed to prepare Workout insert: ' . $this->db->error);

            $stmt->bind_param('sss', $workoutId, $date, $type);
            $stmt->execute();

            foreach ($workoutData['exercises'] ?? [] as $exerciseNum => $exercise) 
            {
                if (!is_array($exercise))
                    continue;

                $exerciseId = bin2hex(random_bytes(16));
                $exerciseName = $exercise['name'] ?? 'Unknown';
                $exerciseNotes = $exercise['notes'] ?? null;
                $exNum = (int)($exercise['number'] ?? ($exerciseNum + 1));

                $stmt = $this->db->prepare(
                    'INSERT INTO Exercise (exercise_id, workout_id, number, name, notes) VALUES (UNHEX(?), UNHEX(?), ?, ?, ?)'
                );
                if (!$stmt)
                    throw new RuntimeException('Failed to prepare Exercise insert: ' . $this->db->error);

                $stmt->bind_param('ssiss', $exerciseId, $workoutId, $exNum, $exerciseName, $exerciseNotes);
                $stmt->execute();

                foreach ($exercise['sets'] ?? [] as $setNum => $set) 
                {
                    if (!is_array($set))
                        continue;

                    $setId = bin2hex(random_bytes(16));
                    $sNum = (int)($set['number'] ?? ($setNum + 1));
                    $reps = (int)($set['reps'] ?? 0);
                    $warmup = !empty($set['warmup']) ? 1 : 0;
                    $dropset = !empty($set['dropset']) ? 1 : 0;
                    $failure = !empty($set['failure']) ? 1 : 0;

                    $stmt = $this->db->prepare(
                        'INSERT INTO Exercise_Set (set_id, exercise_id, number, reps, warmup, dropset, failure) VALUES (UNHEX(?), UNHEX(?), ?, ?, ?, ?, ?)'
                    );
                    if (!$stmt)
                        throw new RuntimeException('Failed to prepare Exercise_Set insert: ' . $this->db->error);

                    $stmt->bind_param('ssiiiii', $setId, $exerciseId, $sNum, $reps, $warmup, $dropset, $failure);
                    $stmt->execute();
                }
            }

            $this->db->commit();
            return true;
        }
        catch (Throwable $e)
        {
            $this->db->rollback();
            error_log('Failed to save workout: ' . $e->getMessage());
            return false;
        }
    }
}
?>
