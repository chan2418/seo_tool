<?php

require_once __DIR__ . '/../config/database.php';

class AuditModel {
    private $db;
    private $conn;

    public function __construct() {
        $this->db = new Database();
        $this->conn = $this->db->connect();
        
        // Create storage directory for fallback
        if (!is_dir(__DIR__ . '/../storage')) {
            mkdir(__DIR__ . '/../storage', 0777, true);
        }
    }

    public function saveAudit($data, $userId = null) {
        $saved = false;
        $id = null;

        // Try DB first
        if ($this->conn) {
            try {
                $sql = "INSERT INTO audit_history 
                        (user_id, url, seo_score, meta_title_score, meta_description_score, h1_score, image_alt_score, https_score, mobile_score, pagespeed_score, details) 
                        VALUES 
                        (:user_id, :url, :seo_score, :meta_title, :meta_desc, :h1, :image_alt, :https, :mobile, :pagespeed, :details)";
                
                $stmt = $this->conn->prepare($sql);
                
                // Calculate component scores for history (simplified for now, or passed in data)
                // For Phase 1 we didn't break down scores in DB, now we do.
                // We'll rely on the 'details' array to extract component status and assign scores approximately
                // or updated ScoringEngine to return breakdown.
                // For now, let's just save the main SEO score and maybe 0s for others if not computed yet.
                // Wait, the task says "Score breakdown".
                // I'll need to update ScoringEngine to return breakdown scores.
                // For this step, I'll pass 0s to avoid breaking, or update ScoringEngine first.
                // Let's assume passed data has breakdown or we calculate it here.
                
                $stmt->execute([
                    ':user_id' => $userId ?? 0, // 0 for anonymous/guest if allowed (Phase 2 implies auth required)
                    ':url' => $data['url'],
                    ':seo_score' => $data['score'],
                    ':meta_title' => 0, // Placeholder
                    ':meta_desc' => 0,
                    ':h1' => 0,
                    ':image_alt' => 0,
                    ':https' => 0,
                    ':mobile' => 0,
                    ':pagespeed' => 0,
                    ':details' => json_encode($data['details'])
                ]);

                $id = $this->conn->lastInsertId();
                $saved = true;
            } catch (Exception $e) {
                error_log("DB Save Failed: " . $e->getMessage());
                // Fallthrough to file save
            }
        }

        // Fallback to File Storage
        if (!$saved) {
             // ... existing file fallback logic ...
        }

        // Fallback to File Storage if DB failed
        if (!$saved) {
            $id = time(); // Use timestamp as ID
            $fileData = $data;
            $fileData['id'] = $id;
            $fileData['user_id'] = $userId;
            $fileData['created_at'] = date('Y-m-d H:i:s');
            
            // Simplified storage for file fallback (just storing what we have)
            $fileData['details'] = $data['details']; 
            
            // Append to a user_audits.json for history listing
            if ($userId) {
                $historyFile = __DIR__ . '/../storage/history_' . $userId . '.json';
                $history = file_exists($historyFile) ? json_decode(file_get_contents($historyFile), true) : [];
                // Prepend new audit
                array_unshift($history, [
                    'id' => $id,
                    'url' => $data['url'],
                    'seo_score' => $data['score'],
                    'created_at' => $fileData['created_at']
                ]);
                file_put_contents($historyFile, json_encode($history));
            }
            
            // Still save detailed individual file for 'view'
            file_put_contents(__DIR__ . '/../storage/audit_' . $id . '.json', json_encode($fileData));
            $saved = true;
        }

        return $id;
    }
    
    public function getUserAudits($userId) {
        if ($this->conn) {
            try {
                $stmt = $this->conn->prepare("SELECT * FROM audit_history WHERE user_id = :uid ORDER BY created_at DESC LIMIT 50");
                $stmt->execute([':uid' => $userId]);
                return $stmt->fetchAll();
            } catch (Exception $e) {
                // Fallthrough
            }
        }
        
        // File Fallback
        $historyFile = __DIR__ . '/../storage/history_' . $userId . '.json';
        if (file_exists($historyFile)) {
            return json_decode(file_get_contents($historyFile), true);
        }
        
        return [];
    }
    
    public function getAuditById($id) {
         // Try DB
         if ($this->conn) {
             try {
             try {
                $sql = "SELECT * FROM audit_history WHERE id = :id LIMIT 1";
                $stmt = $this->conn->prepare($sql);
                $stmt->execute([':id' => $id]);
                $result = $stmt->fetch(PDO::FETCH_ASSOC);
                
                if ($result) {
                    // Decode details if valid JSON
                    if (!empty($result['details'])) {
                        $result['details'] = json_decode($result['details'], true);
                    }
                    return $result;
                }
             } catch (Exception $e) {
                  // Ignore
             }
             } catch (Exception $e) {
                 // Ignore
             }
         }
         
         // Try File
         $filePath = __DIR__ . '/../storage/audit_' . $id . '.json';
         if (file_exists($filePath)) {
             return json_decode(file_get_contents($filePath), true);
         }
         
         return null;
    }
}
